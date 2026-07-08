# Writing plugins

Plugins are plain objects passed to `GreenlightConfig::plugins()` in `greenlight.php`. Greenlight discovers what a plugin can do from the interfaces it implements; one object may implement several. Unlike most test frameworks, subscribers receive live runtime context: the actual test instance, its metadata, and access to harness services, not a sanitised copy.

```php
return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(new FlakyQuarantine(), new SlackNotifier());
```

## How plugins reach workers

Tests execute in worker processes, and live objects cannot cross a process boundary. Each worker therefore loads `greenlight.php` itself and constructs the plugins on its side. Two consequences follow: plugin constructors run once per worker, and plugins cannot share in-memory state across workers. A plugin that needs cross-worker state must keep it externally (a file, a socket, a service).

## Capability interfaces

### TestLifecycleSubscriber (worker-side)

```php
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\TestLifecycleSubscriber;

final class FlakyQuarantine implements TestLifecycleSubscriber
{
    public function beforeTest(TestContext $context): void {}

    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        if ($result->outcome->isSuccessful() || !\in_array('quarantined', $context->metadata->groups, true)) {
            return $result;
        }

        return $result->withOutcome(Outcome::Skipped, self::class);
    }
}
```

`beforeTest` runs after the test instance is constructed and before the `#[Before]` hooks. Calling `$context->skip('reason')` from it abandons the attempt and reports the test as skipped; the method is typed `never`, so nothing after the call runs. It works by throwing `Greenlight\Plugin\SkipTest`, declared as `@throws` on the interface, and throwing that yourself is equivalent. Any other throwable errors the test with your plugin named.

`afterTest` receives the finished result and returns it, replaced or untouched. Outcome changes are only legal through `TestResult::withOutcome()`, which records who changed what: a replacement that flips the outcome without growing the transformation log errors the test, naming the plugin. Reports stay trustworthy because transformations are always attributable.

`TestContext` carries the live test `instance`, the `TestId`, the `TestMetadata`, and `service(SomeType::class)` resolving from the active harness scopes. `service()` is usable during `beforeTest` and the test itself; by `afterTest` the per-test scope has closed and it throws.

### RetryDecider (worker-side)

```php
public function shouldRetry(TestMetadata $metadata, TestResult $result, int $attempt, ?\Throwable $cause): bool;
```

Consulted after each unsuccessful attempt; any decider answering yes triggers a fresh attempt with a fresh instance and scope. The built-in `#[Retry]` attribute is itself implemented through this interface.

### RunLifecycleSubscriber (orchestrator-side)

```php
public function onRunEvent(Event $event): void;
```

Receives the whole event stream (run, worker, class, and test events) as it reaches the orchestrator process. Observation only: results cannot be altered from this side of the process boundary, and a throwing run subscriber fails the run loudly.

### HarnessProvider

```php
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;

final class DatabaseProvider implements HarnessProvider
{
    public function services(): array
    {
        return [
            new ServiceDefinition(TestDatabase::class, Scope::PerSuite, static fn() => TestDatabase::migrate()),
        ];
    }
}
```

Contributed services become constructor-injectable in tests, scoped `PerTest`, `PerClass`, `PerSuite`, or `PerRun` (per worker lifetime). Services are lazy: an injected but untouched service is never constructed. A service implementing `Greenlight\Harness\Disposable` is disposed when its scope closes, in reverse creation order, and a disposal that throws `ExpectationFailed` fails the test with diffs, which is how auto-verifying services (the built-in doubles among them) work.

### ServiceResolver (in Greenlight\Harness)

```php
public function resolve(string $type, array $attributes): ?object;
```

Fallback resolution for constructor parameter types no harness service covers. Registered services always win; on a miss, resolvers are consulted in registration order with the parameter's declared type and its instantiated attributes, and the first non-null answer is injected. Returning null passes; answering with anything that is not an instance of the requested type errors the test with the resolver named. Objects supplied this way belong to the resolver's world: harness scopes never track or dispose them.

This is the interface the Symfony bridge implements to inject container services (see [Testing Symfony applications](symfony.md)); any other dependency container can be bridged the same way.

### ExpectationExtension (in Greenlight\Expect)

```php
final class UuidMatchers implements ExpectationExtension
{
    public function matchers(): array
    {
        return [
            'toBeValidUuid' => static fn(mixed $subject): bool => \is_string($subject)
                && \preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $subject) === 1,
        ];
    }
}
```

Extension matchers dispatch through the expectation chain (`$this->expect->that($id)->toBeValidUuid()`), honour `not()`, and cannot shadow native matchers. Declare matcher parameters with real native types: they are enforced at runtime by PHP itself and read by the PHPStan extension below.

#### Static analysis for extension matchers

Matcher calls dispatch through `__call`, which PHPStan cannot check by itself. Greenlight ships a PHPStan extension that closes the gap: it loads your config files the same way workers do, reflects each matcher closure's signature, and presents every matcher to PHPStan as a real method on the expectation chain. Name typos, wrong argument counts, and wrong argument types then fail `phpstan analyse` like any other error.

```neon
includes:
    - vendor/greenlight/greenlight/extension.neon

parameters:
    greenlight:
        configFiles:
            - greenlight.php
```

Static analysis is only half the tooling story: IDE completion comes from indexers, which never run PHPStan plugins. `greenlight ide-helper` covers that side by writing `_greenlight_ide_helper.php`, a never-executed duplicate declaration of the expectation chain whose docblock lists every configured matcher as an `@method` annotation; PhpStorm and Intelephense merge duplicate declarations, so your matchers autocomplete with real signatures. Gitignore the file and regenerate it after changing matchers. Both tools read the same matcher map, so completion and analysis cannot disagree.

Relative paths resolve against the directory PHPStan runs from. Listing several config files unions their matchers; the same matcher name declared with two different signatures fails the analysis run loudly, because one analysis can only hold one signature per name. Plugin constructors run inside the PHPStan process when the map is first needed, exactly as they do inside each worker.

### Reporter (in Greenlight\Reporting)

Implement `onEvent(Event $event): void` plus `finish(): void` to render the event stream into any format. The six built-in reporters are ordinary implementations of the same interface.

## Ordering and error policy

Subscribers run in registration order. A plugin additionally implementing `Greenlight\Plugin\Prioritized` (`priority(): int`, lower runs earlier, default 0) is stably sorted first. Plugin failures are never swallowed: worker-side failures error the affected test with the plugin named, orchestrator-side failures fail the run.

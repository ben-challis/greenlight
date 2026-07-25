# Writing plugins

Plugins are plain objects passed to `GreenlightConfig::plugins()` in
`greenlight.php`. Greenlight works out what a plugin can do from the interfaces
it implements. A single plugin can implement more than one interface.

Plugin capabilities run either in the orchestrator or in workers. Each
capability section below names its side.

```php
return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(new FlakyQuarantine(), new SlackNotifier());
```

## How plugins reach workers

Tests run in worker processes, and live PHP objects cannot be sent across a
process boundary. Each worker loads `greenlight.php` and creates its own plugin
instances.

The orchestrator also loads `greenlight.php`, so plugin constructors run there
and once per worker. Plugin properties do not cross that boundary. Use
integration resources for data that workers need from the orchestrator.

## Capability interfaces

### IntegrationFixtureProvider

Orchestrator-side.

Integration fixtures own external infrastructure that must outlive a worker
process, such as a database server, broker, container, or remote test tenant.

```php
use Greenlight\Harness\FixtureResource;
use Greenlight\Plugin\IntegrationFixtureContext;
use Greenlight\Plugin\IntegrationFixtureDefinition;
use Greenlight\Plugin\IntegrationFixtureProvider;

final class BrokerFixtures implements IntegrationFixtureProvider
{
    public function integrationFixtures(): array
    {
        return [
            new IntegrationFixtureDefinition(
                'broker',
                static function (IntegrationFixtureContext $context): void {
                    $broker = TestBroker::start();
                    $context->defer(static fn() => $broker->stop());

                    $channels = [];

                    foreach ($context->channels() as $channel) {
                        $channels[$channel] = FixtureResource::from(
                            values: ['tenant' => 'test_' . $channel],
                            secrets: ['token' => $broker->tokenFor($channel)],
                        );
                    }

                    $context->expose(
                        FixtureResource::from(values: ['host' => $broker->host()]),
                        $channels,
                    );
                },
            ),
        ];
    }
}
```

Greenlight provisions after discovery, selection, and sharding, but before
`RunStarted` or worker spawn. It does not provision for an empty plan,
`list-tests`, `--list-groups`, `--list-suites`, or `--dry-run`.

Definitions can depend on other fixtures:

```php
new IntegrationFixtureDefinition(
    'schema',
    static function (IntegrationFixtureContext $context): void {
        foreach ($context->channels() as $channel) {
            $database = $context->dependency('postgres', $channel);
            // Migrate this channel's database.
        }
    },
    dependsOn: ['postgres'],
);
```

Dependencies provision first. Cleanup callbacks run in reverse registration
order. Register cleanup immediately after acquiring a resource so it also runs
when later provisioning fails. Missing dependencies, duplicate IDs, and cycles
fail before provisioning starts.

`IntegrationFixtureContext` exposes:

* `runId()`: the identifier also used by run lifecycle events
* `configuredWorkers()`: configured worker count
* `channels()`: channel numbers available to the selected plan
* `shard()`: one-based shard index and shard count, or `null`
* `dependency()`: a declared dependency's shared or channel resource
* `defer()`: register cleanup
* `expose()`: publish shared data and per-channel overlays

`FixtureResource` accepts JSON-safe values and UTF-8 strings. Each worker's
complete resource payload is limited to 1 MiB.

Tests can inject `IntegrationResources` directly:

```php
final class PublishesMessageTest
{
    public function __construct(
        private readonly IntegrationResources $resources,
    ) {}

    #[Test]
    public function publishes(): void
    {
        $broker = $this->resources->fixture('broker');
        $client = new BrokerClient(
            $broker->string('host'),
            $broker->string('tenant'),
            $broker->secret('token')->reveal(),
        );
    }
}
```

Shared values are merged with the current channel overlay. Other channel
overlays are not sent to the worker.

#### Fixture lifecycle

One fixture graph belongs to one selected run. Repeat iterations, watch reruns,
and shards provision separately. Retries and replacement workers reuse the
current graph. Fixtures are run-scoped, not suite-scoped.

Teardown runs after `RunFinished` and on failed provisioning, worker startup,
execution, reporting, or graceful shutdown. Greenlight attempts every callback.
See [Orchestrator-owned integration fixtures](architecture/orchestrator-integration-fixtures.md)
for the full lifecycle and hard-termination limits.

#### Secrets

Put credentials in `secrets`, not `values`. `SensitiveValue::reveal()` returns
the string; object dumps and exports redact it. Resources travel over the local
authenticated worker socket, not through environment variables or command
arguments.

The orchestrator and matching worker still hold the plaintext. Do not include
it in IDs, exceptions, logs, or test names.

### WorkerBootstrapSubscriber

Worker-side.

This hook runs once per physical worker after resources arrive and before
`HarnessProvider::services()` or `ServiceResolver` is used. It can turn
serializable resource data into worker-local services:

```php
final class BrokerPlugin implements WorkerBootstrapSubscriber, HarnessProvider
{
    private ?FixtureResource $broker = null;

    public function onWorkerBootstrap(WorkerBootstrapContext $context): void
    {
        $this->broker = $context->resources->fixture('broker');
    }

    public function services(): array
    {
        $broker = $this->broker
            ?? throw new \LogicException('Broker resources were not bootstrapped.');

        return [
            new ServiceDefinition(
                BrokerClient::class,
                Scope::PerRun,
                static fn() => new BrokerClient(
                    $broker->string('host'),
                    $broker->secret('token')->reveal(),
                ),
            ),
        ];
    }
}
```

`WorkerBootstrapContext` contains the `workerId`, `TestChannel`, and
`IntegrationResources`. Subscribers may implement `Prioritized`; lower values
run first. An exception fails the run before tests begin.

### TestLifecycleSubscriber

Worker-side.

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

`beforeTest()` runs after the test instance is constructed and before any
`#[Before]` hooks.

Call `$context->skip('reason')` to stop the attempt and report the test as
skipped. The method is typed `never`, so code after the call does not run. It
throws `Greenlight\Core\Test\SkipTest`, which is declared on the interface; throwing
that exception yourself has the same effect. Any other throwable marks the test
as errored and names the plugin.

`afterTest()` receives the finished result and must return a result, either the
same one or a replacement.

Outcome changes must go through `TestResult::withOutcome()`. That records which
plugin changed the result. If a plugin returns a result whose outcome changed
without adding to the transformation log, the test errors and names the plugin.

`TestContext` contains the live test `instance`, the `TestId`, the
`TestMetadata`, and `service(SomeType::class)` for resolving services from the
active harness scopes.

`service()` is available during `beforeTest()` and during the test itself. By
`afterTest()`, the per-test scope has already closed, so `service()` throws.

### RetryDecider

Worker-side.

```php
public function shouldRetry(TestMetadata $metadata, TestResult $result, int $attempt, ?\Throwable $cause): bool;
```

Retry deciders are asked after each unsuccessful attempt. If any decider returns
`true`, Greenlight runs a fresh attempt with a fresh test instance and scope.

The built-in `#[Retry]` attribute is implemented through this interface.

### RunLifecycleSubscriber

Orchestrator-side.

```php
public function onRunEvent(Event $event): void;
```

Run subscribers receive the event stream in the orchestrator process. This
includes run, worker, class, and test events.

This side is observation-only. Results cannot be changed across the process
boundary. Integration fixture provisioning completes before `RunStarted`;
`RunFinished` is delivered before fixture teardown begins. If a run subscriber
throws, the run fails and fixtures are still torn down.

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

Harness providers contribute services that tests can receive through constructor
injection.

Services can be scoped as `PerTest`, `PerClass`, `PerSuite`, or `PerRun`.
`PerRun` means the physical worker lifetime, not the orchestrator-owned
integration fixture lifetime. Services are lazy, so a service is not constructed
unless it is actually used.

If a service implements `Greenlight\Harness\Disposable`, it is disposed when its
scope closes. Disposal happens in reverse creation order.

If disposal throws `ExpectationFailed`, the test fails with diffs. This is how
auto-verifying services work, including Greenlight's built-in doubles.

### ServiceResolver

In `Greenlight\Harness`.

```php
public function resolve(string $type, array $attributes): ?object;
```

A service resolver is fallback constructor injection for types that no harness
service provides.

Registered harness services always win. If no service matches, resolvers are
called in registration order with the parameter's declared type and instantiated
attributes. The first non-null object is injected.

Return `null` to pass. Returning an object that is not an instance of the
requested type errors the test and names the resolver.

Objects returned by a resolver are owned by the resolver. Harness scopes do not
track or dispose them.

The Symfony bridge uses this interface to inject container services. Other
dependency containers can be bridged the same way. See
[Testing Symfony applications](symfony.md).

### ExpectationExtension

In `Greenlight\Expect`.

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

Extension matchers are called through the expectation chain:

```php
Expect::that($id)->toBeValidUuid();
```

They support `not()` and cannot replace native matchers.

Declare matcher parameters with normal native PHP types. PHP enforces those
types at runtime, and Greenlight's PHPStan extension reads them for static
analysis.

#### Static analysis for extension matchers

Matcher calls are dispatched through `__call`, so PHPStan cannot check them on
its own.

Greenlight includes a PHPStan extension for matcher calls. It loads your
Greenlight config files the same way workers do, reflects each matcher closure,
and exposes every matcher to PHPStan as a real method on the expectation chain.

Typos, wrong argument counts, and wrong argument types then fail
`phpstan analyse` like any other error.

```neon
includes:
    - vendor/greenlight/greenlight/extension.neon

parameters:
    greenlight:
        configFiles:
            - greenlight.php
```

PHPStan covers analysis, but IDE completion needs a separate file because IDE
indexers do not run PHPStan plugins.

Run `greenlight ide-helper` to generate `_greenlight_ide_helper.php`. The file is
never executed. It declares a duplicate expectation chain with `@method`
annotations for every configured matcher. PhpStorm and Intelephense merge the
duplicate declaration, so configured matchers autocomplete with their real
signatures.

Gitignore the helper file and regenerate it after changing matchers.

Both PHPStan and the IDE helper use the same matcher map, so analysis and
completion stay in sync.

Relative config paths are resolved from the directory where PHPStan runs.
Listing multiple config files unions their matchers. If the same matcher name is
declared with different signatures, analysis fails, because a single analysis run
can only have one signature for a matcher name.

Plugin constructors run inside the PHPStan process when the matcher map is first
loaded, just as they run inside each worker.

### Reporter

In `Greenlight\Reporting`.

Implement `onEvent(Event $event): void` and `finish(): void` to render the event
stream in another format.

Greenlight's built-in reporters use the same interface.

## Ordering and error policy

Subscribers run in registration order.

A plugin can also implement `Greenlight\Plugin\Prioritized`:

```php
public function priority(): int;
```

Lower numbers run earlier. The default priority is `0`. Sorting is stable, so
plugins with the same priority keep their registration order.

Plugin failures are not swallowed. Worker-side failures error the affected test
and name the plugin. Orchestrator-side failures fail the run.

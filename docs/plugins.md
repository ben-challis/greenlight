# Plugins

Plugins implement one or more capability interfaces. Pass one factory for each
plugin to `GreenlightConfig::plugins()` in `greenlight.php`. Each factory MUST
declare its concrete plugin class as its return type. The factory MUST return a
new instance on each call.

Plugin capabilities run either in the orchestrator or in workers. Each
capability section below names its side.

<!-- php-example {"example":"plugins-example-01","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(
        static fn(): FlakyQuarantine => new FlakyQuarantine(),
        static fn(): SlackNotifier => new SlackNotifier(),
    );
```

## Plugin instances and ownership

Greenlight creates plugin instances only for an owner that uses one of their
capabilities. It creates one command-owned instance for each factory that
has `ReporterProvider`. It creates one run-owned orchestrator instance for each
factory that has a run capability. It creates one worker instance for each
factory that has a worker capability and for each physical worker.

A plugin that has capabilities on both sides gets one instance on each side.
The instances are separate with one in-process worker and with parallel
workers. Capabilities with the same owner and lifetime use the same instance.
A plugin that has `ReporterProvider` and a run capability gets separate command
and run instances. Priority applies independently to each capability.

Each repeat iteration and watch rerun creates a new orchestrator instance and
new worker instances. A replacement parallel worker also gets a new instance.
Assignments and retries in one physical worker use its existing instance.
Greenlight calls the configured factory for each instance. It does not clone a
plugin object.

Plugin properties do not cross the orchestrator and worker seam. Do not use a
property to transfer fixture data. An `IntegrationFixtureProvider` MUST expose
data through integration resources. Tests can inject `IntegrationResources`.
A `WorkerBootstrapSubscriber` can also read the resources and configure other
worker capabilities on its worker-local instance.

## Capability interfaces

### ReporterProvider

Orchestrator-side.

A `ReporterProvider` adds named reporter factories to `--reporter`. Return one
`ReporterDefinition` for each name.

<!-- php-example {"example":"plugins-example-reporter-provider","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Plugin\ReporterProvider;
use Greenlight\Reporting\Output\Output;
use Greenlight\Reporting\Reporter;
use Greenlight\Reporting\ReporterDefinition;

final class CompanyReporters implements ReporterProvider
{
    public function reporters(): array
    {
        return [
            new ReporterDefinition(
                'company-json',
                static fn (Output $output): Reporter => new CompanyJsonReporter($output),
            ),
        ];
    }
}

return GreenlightConfig::create()
    ->plugins(static fn(): CompanyReporters => new CompanyReporters());
```

Select the reporter by name:

```sh
vendor/bin/greenlight run --reporter=company-json
```

A reporter name starts with a lowercase ASCII letter. It contains only
lowercase ASCII letters, digits, and hyphens.

Built-in and custom names share one registry. Each name MUST be unique. A
duplicate name stops the command before the test run starts.

Greenlight calls `reporters()` one time for each command. It calls a selected
factory for each standard, repeat, or watch run. A repeated selection calls the
factory one time for each occurrence.

Each factory MUST return a new `Reporter`. Greenlight supplies the `Output` and
owns it. A reporter MUST NOT close the output.

Multiple selected reporters receive events in `--reporter` order. Greenlight
also calls `finish()` in that order. It calls `finish()` one time after the
final event, or after a contained run error.

If a provider or factory throws, Greenlight reports the name and stops the
command. An invalid factory result also stops the command before test execution.

If a reporter callback throws `ReportGenerationFailed`, Greenlight stops that callback.
Later reporters do not receive the event or finish signal from that callback.

Shell completions suggest the built-in names. A configured name remains valid
when it does not occur in the suggestions.

### IntegrationFixtureProvider

Orchestrator-side.

Integration fixtures own external infrastructure that must outlive a worker
process, such as a database server, broker, container, or remote test tenant.

<!-- php-example {"example":"plugins-example-02","file":"snippet.php","mode":"file","tools":["rector"]} -->
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

<!-- php-example {"example":"plugins-example-03","file":"snippet.php","mode":"statements","tools":["rector"]} -->
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

Fixture IDs must be non-empty UTF-8 strings. They must not use integer strings
because PHP converts those map keys to integers.

Dependencies provision first. Cleanup callbacks run in reverse registration
order. Register cleanup immediately after resource acquisition. This makes
cleanup available if a later provisioning operation fails. Missing
dependencies, duplicate IDs, and cycles fail before provisioning starts.

`IntegrationFixtureContext` exposes:

* `runId()`: the identifier also used by run lifecycle events
* `configuredWorkers()`: configured worker count
* `channels()`: channel numbers available to the selected plan
* `shard()`: one-based shard index and shard count, or `null`
* `dependency()`: a declared dependency's shared or channel resource
* `defer()`: register cleanup
* `expose()`: publish shared data and per-channel overlays

`FixtureResource` accepts JSON-safe values and UTF-8 strings. Greenlight limits
each worker's complete resource payload to 1 MiB.

Tests can inject `IntegrationResources` directly:

<!-- php-example {"example":"plugins-example-04","file":"snippet.php","mode":"file","tools":["rector"]} -->
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

Greenlight merges shared values with the current channel overlay. It does not
send other channel overlays to the worker.

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
the string. Object dumps and exports redact it. Resources travel over the local
authenticated worker socket, not through environment variables or command
arguments.

The orchestrator and matching worker still hold the plaintext. Do not include
it in IDs, exceptions, logs, or test names.

### WorkerBootstrapSubscriber

Worker-side.

This hook runs once per physical worker after resources arrive and before
Greenlight uses `HarnessProvider::services()` or `ServiceResolver`. It can turn
serializable resource data into worker-local services:

<!-- php-example {"example":"plugins-example-05","file":"snippet.php","mode":"file","tools":["rector"]} -->
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
                Scope::PerWorker,
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
`IntegrationResources`. Subscribers may implement `Prioritized`. Lower values
run first. An exception fails the run before tests begin.

### WorkerRuntimeRunner

Worker-side.

<!-- php-example {"mode":"display","reason":"Shows one method signature without its interface declaration."} -->
```php
public function runWorker(\Closure $worker): mixed;
```

This capability puts all assignments for one physical worker in a runtime
boundary. Greenlight calls it after worker bootstrap and before it reports that
the worker is ready.

Call the callback once and return its value. Use `finally` to close the runtime
when the worker drains, recycles, disconnects, or throws. Worker-scope harness
services close before the callback returns.

Greenlight nests multiple runtime boundaries in priority order. A boundary
failure stops the worker. Greenlight sends a final worker message only after
all runtime boundaries close successfully.

The Hyperf bridge uses this capability for its long-running root Swoole
runtime. See [Hyperf applications](hyperf.md).

### BeforeTestSubscriber

Worker-side.

<!-- php-example {"mode":"display","reason":"Shows one method signature without its interface declaration."} -->
```php
public function beforeTest(TestContext $context): void;
```

`beforeTest()` runs after Greenlight constructs the test instance. It runs once
before all `#[Before]` hooks.

Call `$context->skip('reason')` to stop the attempt and report the test as
skipped. The method has the type `never`, so code after the call does not run.
It throws `Greenlight\Core\Test\SkipTest`. The interface declares this
exception. A direct throw of this exception has the same effect. A different
throwable causes an error result that names the plugin.

### AfterTestSubscriber

Worker-side.

<!-- php-example {"example":"plugins-example-07","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\AfterTestSubscriber;

final class FlakyQuarantine implements AfterTestSubscriber
{
    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        if ($result->outcome->isSuccessful() || !\in_array('quarantined', $context->metadata->groups, true)) {
            return $result;
        }

        return $result->withOutcome(Outcome::Skipped, self::class);
    }
}
```

`afterTest()` receives the finished result and must return a result, either the
same one or a replacement.

Use `TestResult::withOutcome()` for each outcome change. This method records the
plugin that changed the result. If a plugin changes the outcome without a
transformation-log entry, Greenlight reports an error and names the plugin.

`TestContext` contains the live test `instance`, the `TestId`, the
`TestMetadata`, and `attachments`. Its `service(SomeType::class)` method
resolves services from the active harness scopes.

`$context->attachments` is the same attempt-owned
`Greenlight\Core\Artifact\Attachments` object a test can receive through
constructor injection. Plugins can add attachments in either hook. This
includes an attachment after a failure inspection in `afterTest()`. The usual
retention and size limits apply. See [attachments](attachments.md).

The `service()` method is available during `beforeTest()` and the test. The
per-test scope closes before `afterTest()`, so `service()` throws in that hook.

### TestAttemptRunner

Worker-side.

<!-- php-example {"mode":"display","reason":"Shows one method signature without its interface declaration."} -->
```php
public function runTestAttempt(\Closure $attempt): mixed;
```

This capability puts one complete attempt in a runtime boundary. The callback
contains constructor injection, hooks, the test, scope disposal, and
`afterTest()` subscribers.

Call the callback one time and return its value. Use `finally` to leave the
runtime boundary when the callback throws.

Greenlight converts a boundary failure to an error result. It can then apply
the normal retry policy.

The Hyperf bridge uses this capability to run each attempt in one Swoole
coroutine. See [Hyperf applications](hyperf.md).

### RetryDecider

Worker-side.

<!-- php-example {"mode":"display","reason":"Shows one method signature without its interface declaration."} -->
```php
public function shouldRetry(TestMetadata $metadata, TestResult $result, int $attempt, ?\Throwable $cause): bool;
```

After an unsuccessful attempt, Greenlight asks retry deciders until one returns
`true`. Greenlight then starts a new attempt with a new test instance and
scope.

The result contains metadata for the attachments from that attempt. A decider
can inspect names, kinds, sizes, and media types. It cannot read the attachment
content.

The built-in `#[Retry]` attribute uses this interface.

### RunLifecycleSubscriber

Orchestrator-side.

<!-- php-example {"mode":"display","reason":"Shows one method signature without its interface declaration."} -->
```php
public function onRunEvent(Event $event): void;
```

Run subscribers receive the event stream in the orchestrator process. The
stream contains run, worker, class, and test events.

Run subscribers cannot change results across the process boundary. Integration
fixture provisioning completes before `RunStarted`.
`RunFinished` is delivered before fixture teardown begins. If a run subscriber
throws, the run fails and fixtures are still torn down.

### HarnessProvider

<!-- php-example {"example":"plugins-example-11","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;

final class DatabaseProvider implements HarnessProvider
{
    public function services(): array
    {
        return [
            new ServiceDefinition(TestDatabase::class, Scope::PerWorker, static fn() => TestDatabase::migrate()),
        ];
    }
}
```

Harness providers supply services to test constructors.

Services can be scoped as `PerTest`, `PerClass`, or `PerWorker`.
`PerWorker` means the physical worker lifetime. It does not mean the
orchestrator-owned integration fixture lifetime. Services are lazy. Greenlight
constructs a service only when a test uses it.

If a service implements `Greenlight\Harness\Disposable`, Greenlight calls its
disposal method when the scope closes. Greenlight uses reverse creation order.

If disposal throws `ExpectationFailed`, the test fails with diffs. This
mechanism gives services automatic verification. The built-in Greenlight
doubles use this mechanism.

### ServiceResolver

In `Greenlight\Harness`.

<!-- php-example {"mode":"display","reason":"Shows one method signature without its interface declaration."} -->
```php
public function resolve(string $type, array $attributes): ?object;
```

A service resolver is a fallback source for a constructor parameter type.

Registered harness services always take precedence. If no service matches,
Greenlight calls resolvers in registration order. Each call receives the
declared parameter type and the attribute instances. Greenlight injects the
first non-null object.

If the resolver cannot supply the requested type, return `null`. If it returns
an object of a different type, the test has an error and names the resolver.

The resolver owns each object that it returns. Harness scopes do not track or
call disposal methods on these objects.

The framework bridges use this interface to inject container services. See
[Symfony applications](symfony.md), [Laravel applications](laravel.md), and
[Tempest applications](tempest.md).

### ExpectationExtension

In `Greenlight\Expect`.

<!-- php-example {"example":"plugins-example-13","file":"snippet.php","mode":"file","tools":["rector"]} -->
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

Call extension matchers through the expectation chain:

<!-- php-example {"example":"plugins-example-14","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
Expect::that($id)->toBeValidUuid();
```

Extension matchers support `not()` and cannot replace native matchers.

Extension matchers also work with `eventually()` and `consistently()`.
Greenlight calls the predicate for each value from the probe. The matcher counts
as one expectation. A predicate exception stops the poll operation.

Declare matcher parameters with normal native PHP types. PHP enforces these
types at run time. Greenlight's PHPStan extension reads them for static
analysis.

Declare `bool` as the matcher return type. An absent or `mixed` return type
remains unresolved. PHPStan reports other declared types when code calls the
matcher.

#### PHPStan support for extension matchers

The expectation chain sends matcher calls through `__call`. PHPStan cannot
check these calls without an extension.

Greenlight includes a PHPStan extension for matcher calls. It loads your
Greenlight configuration files in the same way as workers. It reflects each
matcher closure and exposes each matcher as a real expectation-chain method.
This support includes `eventually()` and `consistently()` chains.

Typos, incorrect argument counts, incorrect argument types, and incompatible
return types then cause a normal `phpstan analyse` error.

```neon
includes:
    - vendor/greenlight/greenlight/extension.neon

parameters:
    greenlight:
        configFiles:
            - greenlight.php
```

PHPStan provides analysis. IDE completion needs a separate file because IDE
indexers do not run PHPStan plugins.

Run `vendor/bin/greenlight ide-helper` to generate
`_greenlight_ide_helper.php`. No process executes this file. It declares a
duplicate expectation chain with `@method` annotations for each configured
matcher. PhpStorm and Intelephense merge the duplicate declaration. Thus,
configured matchers have their real signatures in IDE completion.

Add the helper file to `.gitignore`. Regenerate it after a matcher change.

PHPStan and the IDE helper use the same matcher map. Thus, analysis and
completion remain consistent.

PHPStan resolves relative configuration paths from its current directory.
Multiple configuration files supply the union of their matchers. Analysis fails
if the same matcher name has different signatures. One analysis run can use
only one signature for a matcher name.

When PHPStan first loads the matcher map, it creates one instance of each
configured expectation extension in its process. A worker creates and installs
its own expectation extension instance when the worker starts. The extension
instance stays installed for the physical worker lifetime.

## Plugin order and error policy

A plugin can also implement `Greenlight\Plugin\Prioritized`:

<!-- php-example {"mode":"display","reason":"Shows one method signature without its interface declaration."} -->
```php
public function priority(): int;
```

Lower numbers run earlier. The default priority is `0`. The stable sort keeps
the registration order of plugins that have the same priority.

Priority applies to these capabilities:

* `IntegrationFixtureProvider`
* `WorkerBootstrapSubscriber`
* `WorkerRuntimeRunner`
* `TestAttemptRunner`
* `BeforeTestSubscriber`
* `AfterTestSubscriber`
* `RetryDecider`
* `RunLifecycleSubscriber`
* `HarnessProvider`
* `ServiceResolver`

`ExpectationExtension` uses registration order.

For attempt runners, the lower-priority runner is the outer boundary. Each
runner must call the next callback one time.

Before-test subscribers run from low priority to high priority. Subscribers
with the same priority run in registration order. A skip or failure stops the
remaining before-test subscribers.

After-test subscribers run from high priority to low priority. Subscribers
with the same priority run in reverse registration order. Plugins that
implement both capabilities run their callbacks in the exact reverse order.

Greenlight runs all after-test subscribers. It also runs them when a
before-test subscriber stops the attempt.

Greenlight reports all plugin failures. A worker-side failure causes an error
for the affected test and names the plugin. An orchestrator-side failure causes
the run to fail.

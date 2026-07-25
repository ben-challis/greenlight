# Plugins

Plugins implement one or more capability interfaces. Pass each plugin to
`GreenlightConfig::plugins()` in `greenlight.php`. Greenlight identifies the
plugin capabilities from the interfaces. One plugin can implement more than one
interface.

Some capabilities run in the orchestrator and some run in workers. Worker-side
plugins get the live test instance, its metadata, and access to harness
services. Orchestrator-side plugins can observe the run or own external
integration infrastructure.

```php
return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(new FlakyQuarantine(), new SlackNotifier());
```

## How plugins reach workers

Tests run in worker processes. A process cannot send live PHP objects across a
process boundary. Each worker loads `greenlight.php` and creates its own plugin
instances.

The orchestrator also has its own configured plugin instances. That means a
plugin constructor runs in the orchestrator and once per worker. Plugins cannot
share in-memory state across that boundary. `--workers=1` is in-process, but
plugins should not depend on that implementation detail: move cross-process
information through the integration resource API.

## Capability interfaces

### IntegrationFixtureProvider

Orchestrator-side.

Use an integration fixture for real infrastructure that must exist before tests
start and must outlive individual worker processes: a database server, broker,
container stack, emulator, or remote test tenant.

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

                    // Register this immediately after acquisition. It still
                    // runs if later provisioning or worker startup fails.
                    $context->defer(static fn() => $broker->stop());

                    $channels = [];

                    foreach ($context->channels() as $channel) {
                        $tenant = $broker->createTenant('test_' . $channel);
                        $channels[$channel] = FixtureResource::from(
                            values: ['tenant' => $tenant->name],
                            secrets: ['token' => $tenant->token],
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

The provider declares definitions; Greenlight provisions them after discovery,
selection, and sharding, but before `RunStarted` or worker spawn. No provider is
called for an empty plan or an inspection command such as `list-tests`,
`--list-groups`, `--list-suites`, or `--dry-run`.

Each definition has a unique ID, a provisioning closure, and optional
dependencies:

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
order, including callbacks registered by a provisioner that later throws.
Missing dependencies, duplicate IDs, and cycles fail before infrastructure is
created.

`IntegrationFixtureContext` exposes:

* `runId()`: the identifier also used by run lifecycle events
* `configuredWorkers()`: the configured worker ceiling
* `channels()`: the non-empty set of channel slots this selected plan can use
* `shard()`: the selected one-based shard and shard count, or `null`
* `dependency()`: an already-provisioned dependency's shared or merged
  channel resource
* `defer()`: one teardown callback
* `expose()`: shared data and optional per-channel overlays

`FixtureResource` accepts JSON-safe nulls, booleans, finite numbers, UTF-8
strings, lists, and maps. Maps require non-empty UTF-8 string keys and nesting
is limited. Each channel's complete catalog is limited to 1 MiB.

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

Shared values are merged with only the current worker's channel overlay.
Workers cannot inspect another channel's resource data.

#### Fixture lifecycle

Fixtures have one scope: one selected run iteration. They are provisioned again
for every `--repeat` iteration and every watch-mode rerun. A shard provisions
its own independent fixture graph; Greenlight does not coordinate
infrastructure across machines. Fixtures are global to the selected run rather
than to a configured suite, so a provider that needs suite-specific behaviour
must inspect its own plugin configuration.

Retries, classes, and tests reuse the same resources. Worker recycling, worker
crashes, and `#[Isolated]` replacements reuse the resource for the released
channel. Teardown starts after `RunFinished`, or immediately when provisioning,
worker bootstrap, execution, reporting, or shutdown fails.

All registered callbacks are attempted even if one teardown throws. Teardown
failures fail an otherwise successful run and are appended to an existing
failure. On the first SIGINT or SIGTERM, Greenlight drains and then tears down
before returning the conventional signal exit code. No in-process API can
guarantee cleanup after SIGKILL, a second signal, host failure, or the
orchestrator process being forcibly terminated; provisioners should use
idempotent cleanup and external leases for those cases.

#### Secrets

Put credentials in the `secrets` argument, not ordinary `values`. A secret is
returned as `SensitiveValue` and requires an explicit `reveal()` call. Debug
views and exports redact it. Catalogs travel over Greenlight's authenticated
local worker socket and are not placed in `GREENLIGHT_CHANNEL`, another
environment variable, a command argument, or worker diagnostics.

The plaintext necessarily exists in the orchestrator and the matching worker.
Plugin code must not include it in fixture IDs, exception messages, logs, test
names, or ordinary values. For especially sensitive systems, expose a
short-lived credential or a reference to an external secret store instead.

### WorkerBootstrapSubscriber

Worker-side.

This hook runs once per physical worker after its integration resources arrive
and before `HarnessProvider::services()` and `ServiceResolver` instances are
consumed. Use it to adapt serializable connection data into worker-local
services:

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

`WorkerBootstrapContext` contains the physical `workerId`, its injectable
`TestChannel`, and its `IntegrationResources`. Subscribers may implement
`Prioritized`; lower priorities run first. A thrown exception fails worker
bootstrap and therefore the run before tests begin.

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

`beforeTest()` runs after Greenlight constructs the test instance. It runs once
before all `#[Before]` hooks.

Call `$context->skip('reason')` to stop the attempt and report the test as
skipped. The method has the type `never`, so code after the call does not run.
It throws `Greenlight\Core\Test\SkipTest`. The interface declares this
exception. A direct throw of this exception has the same effect. A different
throwable causes an error result that names the plugin.

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

### RetryDecider

Worker-side.

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

```php
public function onRunEvent(Event $event): void;
```

Run subscribers receive the event stream in the orchestrator process. The
stream contains run, worker, class, and test events.

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

Harness providers supply services to test constructors.

Services can be scoped as `PerTest`, `PerClass`, `PerSuite`, or `PerRun`.
`PerRun` means the physical worker lifetime, not the orchestrator-owned
integration fixture lifetime. Services are lazy, so a service is not constructed
unless it is actually used.

If a service implements `Greenlight\Harness\Disposable`, Greenlight calls its
disposal method when the scope closes. Greenlight uses reverse creation order.

If disposal throws `ExpectationFailed`, the test fails with diffs. This
mechanism gives services automatic verification. The built-in Greenlight
doubles use this mechanism.

### ServiceResolver

In `Greenlight\Harness`.

```php
public function resolve(string $type, array $attributes): ?object;
```

A service resolver is a fallback source for a constructor parameter type. Use
it when no harness service provides that type.

Registered harness services always take precedence. If no service matches,
Greenlight calls resolvers in registration order. Each call receives the
declared parameter type and the attribute instances. Greenlight injects the
first non-null object.

If the resolver cannot supply the requested type, return `null`. If it returns
an object of a different type, the test has an error and names the resolver.

The resolver owns each object that it returns. Harness scopes do not track or
call disposal methods on these objects.

The Symfony bridge uses this interface to inject container services. You can
bridge other dependency containers in the same way. See
[Symfony applications](symfony.md).

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

Call extension matchers through the expectation chain:

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

#### PHPStan support for extension matchers

The expectation chain sends matcher calls through `__call`. PHPStan cannot
check these calls without an extension.

Greenlight includes a PHPStan extension for matcher calls. It loads your
Greenlight configuration files in the same way as workers. It reflects each
matcher closure and exposes each matcher as a real expectation-chain method.
This support includes `eventually()` and `consistently()` chains.

Typos, incorrect argument counts, and incorrect argument types then cause a
normal `phpstan analyse` error.

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

When PHPStan first loads the matcher map, it runs plugin constructors in its
process. Each worker also runs the plugin constructors.

### Reporter

In `Greenlight\Reporting`.

Implement `onEvent(Event $event): void` and `finish(): void`. These methods
render the event stream in another format.

The built-in Greenlight reporters use the same interface.

## Plugin order and error policy

Subscribers run in registration order.

A plugin can also implement `Greenlight\Plugin\Prioritized`:

```php
public function priority(): int;
```

Lower numbers run earlier. The default priority is `0`. The stable sort keeps
the registration order of plugins that have the same priority.

Greenlight reports all plugin failures. A worker-side failure causes an error
for the affected test and names the plugin. An orchestrator-side failure causes
the run to fail.

# Hyperf applications

The Hyperf bridge runs Greenlight tests in the Hyperf coroutine runtime. It
does more than pass calls to a PSR-11 container.

The bridge supports Hyperf 3.2 with Swoole 5 or later. It does not support the
Swow engine.

## Setup

Install the Hyperf framework and dependency injector in the application:

```console
composer require hyperf/framework:^3.2 hyperf/di:^3.2
```

Install the Swoole and pcntl extensions for the PHP command that runs
Greenlight.

Register the plugin in `greenlight.php`:

```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Hyperf\HyperfPlugin;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(new HyperfPlugin(dirname(__DIR__)));
```

Give the plugin the application root directory. The directory must contain the
standard `config/container.php` file.

The container file must return a `Psr\Container\ContainerInterface` instance.
It must create a new instance for the test-attempt container lifetime. The
standard Hyperf container file has this behavior.

## Container lifetime

The default `ContainerLifetime::Worker` mode models a long-running Hyperf
worker. Greenlight boots one application container for each physical worker and
reuses that container for all test attempts in the worker.

```php
use Greenlight\Hyperf\ContainerLifetime;
use Greenlight\Hyperf\HyperfPlugin;

new HyperfPlugin(
    dirname(__DIR__),
    containerLifetime: ContainerLifetime::Worker,
);
```

Container singleton state can reach later tests in this mode. This behavior is
intentional. It exposes request data that an application incorrectly stores in
a worker service, global variable, or static property.

Use `ContainerLifetime::TestAttempt` for a new application container in each
test attempt:

```php
new HyperfPlugin(
    dirname(__DIR__),
    containerLifetime: ContainerLifetime::TestAttempt,
);
```

This mode gives deterministic container isolation. It matches the fresh
container strategy in Hyperf's testing helpers. It does not validate that
container services are safe across requests in a long-running worker. Static
properties and global variables still belong to the Greenlight worker process.

## Worker bootstrap

Each Greenlight worker calls `Hyperf\Di\ClassLoader::init()` once. This call
loads scan configuration and generates the necessary AOP proxy classes.

The bridge locks `runtime/container/greenlight.scan.lock` during this call.
The lock prevents parallel workers from writing the Hyperf scan cache at the
same time.

Normal Hyperf scan configuration applies. Keep `scan_cacheable` disabled during
development. If you enable `scan_cacheable`, generate current scan caches before
the test run.

The class-loader step occurs before Greenlight loads test classes in the
worker. Composer can then select generated classes for application services.

In worker-container mode, bootstrap also loads `config/container.php` and
resolves `Hyperf\Contract\ApplicationInterface`. This operation boots the
application once for the physical worker.

## Worker-container lifecycle

The default mode uses this lifecycle:

1. Boot one application container during Greenlight worker bootstrap.
2. Start one root Swoole coroutine runtime for the physical worker.
3. Start one child coroutine for each test attempt.
4. Construct the test and run all hooks, plugins, and the test method.
5. Close the Greenlight test service scope.
6. Call the configured reset callback.
7. End the child coroutine and release its `Hyperf\Context\Context` data.
8. Reuse the application container for the next test attempt.
9. When the worker exits, call the disposal callback inside the root coroutine.
10. Clear Swoole timers and resume Hyperf's worker-exit coordinator.
11. End the root coroutine runtime.

The root runtime contains all assignments that Greenlight sends to one
physical worker. Worker recycling closes this runtime and gives the replacement
worker a new application container.

## Test-attempt container lifecycle

The isolated mode uses this lifecycle for each attempt:

1. Start one root Swoole coroutine runtime.
2. Load `config/container.php` and activate its new container.
3. Resolve `Hyperf\Contract\ApplicationInterface` to boot the application.
4. Construct the test and run all hooks, plugins, and the test method.
5. Close the Greenlight test service scope.
6. Call the reset callback and disposal callback.
7. Remove access to the discarded application container.
8. Clear Swoole timers and resume Hyperf's worker-exit coordinator.
9. End the coroutine runtime.

In both modes, the complete Greenlight attempt runs inside its own coroutine.
This boundary includes constructor injection, hooks, test-scope disposal, and
`afterTest()` plugins. Swoole destroys the coroutine context when the attempt
ends.

## Container services

Declare a service dependency by type:

```php
final readonly class RegistrationTest
{
    public function __construct(private RegistrationHandler $handler) {}
}
```

Greenlight first checks its harness services. It then asks the active Hyperf
container. Normal Hyperf container rules apply.

Use `#[Service]` when the parameter type does not select the necessary ID:

```php
use Greenlight\Hyperf\Service;

public function __construct(
    #[Service('payments.client')] private PaymentClient $client,
) {}
```

The bridge checks the returned service type. A different type causes a test
error.

Tests can also receive `Psr\Container\ContainerInterface`. This service is
available only during the current test attempt.

## Reset and disposal

Hyperf does not define one reset operation for an arbitrary application object
graph. The application MUST keep request state in coroutine context or reset
that state explicitly. See Hyperf's
[coroutine guidance](https://hyperf.wiki/3.1/#/en/coroutine).

Use `reset` for project state that must change after every attempt. Use
`dispose` for resources that belong to the selected container lifetime:

```php
use Psr\Container\ContainerInterface;

new HyperfPlugin(
    dirname(__DIR__),
    reset: static function (ContainerInterface $container): void {
        $container->get(RequestStateProbe::class)->reset();
    },
    dispose: static function (ContainerInterface $container): void {
        $container->get(TestResourceRegistry::class)->close();
    },
);
```

The reset callback runs after Greenlight closes its per-test service scope. It
runs inside the test coroutine in both modes.

The disposal callback runs before Greenlight discards its container. In worker
mode, it runs once when the worker exits. In test-attempt mode, it runs after
each attempt. It always runs inside a coroutine.

The bridge does not reset arbitrary application static properties or global
variables. Reset these values in an `#[After]` hook or the reset callback.

The bridge does not isolate databases, queues, caches, or remote services.

## Parallel resources

Workers run tests at the same time. Use `GREENLIGHT_CHANNEL` in Hyperf
configuration to give each worker separate external resources.

For example, add the channel to database names, cache prefixes, queue names,
and temporary directories. The channel remains stable for the worker slot.

Use `#[RequiresResource]` when an external resource cannot use channels. See
[configuration](configuration.md) for concurrency limits.

## Supported surface

The bridge covers these runtime behaviors:

* Hyperf class scan and AOP proxy generation
* One long-running Swoole runtime for each worker-container worker
* Application boot for the selected container lifetime
* One coroutine context for each complete test attempt
* Persistent or isolated container singleton state
* Project reset and disposal callbacks
* Hyperf timer and coordinator cleanup at the container lifetime boundary
* Type-based and explicit-ID service injection

The bridge does not cover these behaviors:

* A live Swoole HTTP server or server worker events
* Concurrent test attempts inside one Greenlight worker
* Hyperf `co-phpunit` or PHPUnit helper traits
* Swow coroutine execution
* Database transactions or schema creation
* Automatic cleanup for arbitrary static state or external resources

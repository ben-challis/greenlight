# Hyperf applications

The [Hyperf](https://hyperf.io/) bridge runs each Greenlight test attempt in a
Hyperf coroutine.

The bridge supports Hyperf 3.2 with Swoole 5 or later. It does not support the
Swow engine.

## Setup

Install the Hyperf framework and dependency injector in the application:

```console
composer require hyperf/framework:^3.2 hyperf/di:^3.2
```

Enable the Swoole and pcntl extensions for the PHP command that runs
Greenlight.

Register the plugin in `greenlight.php`:

<!-- php-example {"example":"hyperf-example-01","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Hyperf\HyperfPlugin;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(new HyperfPlugin(dirname(__DIR__)));
```

Pass the application root directory to the plugin. The directory MUST contain
the standard `config/container.php` file.

The container file MUST return a `Psr\Container\ContainerInterface` instance.
For the test-attempt container lifetime, the file MUST create a new instance.
The standard Hyperf container file creates a new instance.

## Container lifetime

The default `ContainerLifetime::Worker` mode uses one application container for
each worker. Greenlight reuses the container for all test attempts in that
worker.

<!-- php-example {"example":"hyperf-example-02","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Hyperf\ContainerLifetime;
use Greenlight\Hyperf\HyperfPlugin;

new HyperfPlugin(
    dirname(__DIR__),
    containerLifetime: ContainerLifetime::Worker,
);
```

Container singleton state can reach later tests in this mode. Request data in a
worker service, global variable, or static property can also reach later tests.

Use `ContainerLifetime::TestAttempt` to create an application container for
each test attempt:

<!-- php-example {"example":"hyperf-example-03","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
new HyperfPlugin(
    dirname(__DIR__),
    containerLifetime: ContainerLifetime::TestAttempt,
);
```

This mode uses the fresh-container strategy from Hyperf test helpers. It does
not test if container services are safe across requests in a long-running
worker. Static properties and global variables remain in the Greenlight worker
process.

## Worker bootstrap

Each Greenlight worker calls `Hyperf\Di\ClassLoader::init()` once. This call
loads scan configuration and generates AOP proxy classes.

The bridge locks `runtime/container/greenlight.scan.lock` during this call.
The lock prevents concurrent writes to the Hyperf scan cache.

Keep `scan_cacheable` disabled during development. If you enable
`scan_cacheable`, generate current scan caches before you run tests.

Greenlight initializes the class loader before it loads test classes. Composer
can then load generated classes for application services.

In `ContainerLifetime::Worker` mode, bootstrap also loads
`config/container.php`. It resolves `Hyperf\Contract\ApplicationInterface` to
boot the application once for the worker.

## Worker-container lifecycle

The default mode uses this lifecycle:

1. Boot one application container during Greenlight worker bootstrap.
2. Start one root Swoole coroutine for the worker.
3. Start one child coroutine for each test attempt.
4. Construct the test and run all hooks, plugins, and the test method.
5. Close the Greenlight test service scope.
6. Call the configured reset callback.
7. End the child coroutine and release its `Hyperf\Context\Context` data.
8. Reuse the application container for the next test attempt.
9. When the worker exits, call the disposal callback inside the root coroutine.
10. Clear Swoole timers and resume Hyperf's worker-exit coordinator.
11. End the root coroutine.

The root coroutine contains all assignments that Greenlight sends to one
worker. Worker replacement closes this coroutine and creates a new application
container.

## Test-attempt container lifecycle

The isolated mode uses this lifecycle for each attempt:

1. Start one root Swoole coroutine.
2. Load `config/container.php` and activate its new container.
3. Resolve `Hyperf\Contract\ApplicationInterface` to boot the application.
4. Construct the test and run all hooks, plugins, and the test method.
5. Close the Greenlight test service scope.
6. Call the reset callback and disposal callback.
7. Remove access to the discarded application container.
8. Clear Swoole timers and resume Hyperf's worker-exit coordinator.
9. End the coroutine.

In both modes, the complete test attempt runs inside its own coroutine. This
includes constructor injection, hooks, test-scope disposal, and `afterTest()`
plugins. Swoole destroys the coroutine context when the attempt ends.

## Container services

Declare a service dependency by type:

<!-- php-example {"example":"hyperf-example-04","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
final readonly class RegistrationTest
{
    public function __construct(private RegistrationHandler $handler) {}
}
```

Greenlight first checks its harness services. It then asks the active Hyperf
container.

Use `#[Service]` when the parameter type does not select the necessary ID:

<!-- php-example {"example":"hyperf-example-05","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
use Greenlight\Hyperf\Service;

public function __construct(
    #[Service('payments.client')] private PaymentClient $client,
) {}
```

The bridge checks the returned service type. A different type causes a test
error.

A test can also receive `Psr\Container\ContainerInterface`. This service is
available only during the current test attempt.

## Reset and disposal

Hyperf does not supply one reset operation for all application services. The
application MUST keep request state in coroutine context or reset that state
after each attempt. See Hyperf's
[coroutine guidance](https://hyperf.wiki/3.1/#/en/coroutine).

Use `reset:` to reset project state after each attempt. Use
`dispose:` for resources that belong to the selected container lifetime:

<!-- php-example {"example":"hyperf-example-06","file":"snippet.php","mode":"file","tools":["rector"]} -->
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

The bridge does not reset application static properties or global variables.
Reset these values in an `#[After]` hook or the `reset:` callback.

## Parallel resources

Workers run tests at the same time. Use `GREENLIGHT_CHANNEL` in Hyperf
configuration to assign separate external resources to each worker.

Add the channel to database names, cache prefixes, queue names, and temporary
directories. The channel remains stable for the worker slot.

If workers cannot use separate external resources, use `#[RequiresResource]`.
See [configuration](configuration.md) for concurrency limits.

## Supported features

The bridge provides:

* Hyperf class scan and AOP proxy generation
* One root Swoole coroutine for each worker container
* Application boot for the selected container lifetime
* One coroutine context for each complete test attempt
* Persistent or isolated container singleton state
* Project reset and disposal callbacks
* Hyperf timer and coordinator cleanup at the container lifetime boundary
* Type-based and explicit-ID service injection

The bridge does not provide:

* A live Swoole HTTP server or server worker events
* Concurrent test attempts inside one Greenlight worker
* Swow coroutine execution

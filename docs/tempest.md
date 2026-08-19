# Tempest applications

The Tempest bridge supplies Tempest container services and built-in Greenlight
harness services to test constructors.

The bridge boots one long-running Tempest kernel for each worker. The kernel
owns application discovery, configuration, deferred tasks, reset, and shutdown
events.

## Compatibility

Greenlight requires PHP 8.4 or later. Greenlight has no Tempest runtime
dependency.

Tempest 3.18 requires PHP 8.5. Install Tempest only in an application that uses
PHP 8.5 or later:

```console
composer require tempest/framework:^3.18
```

Greenlight tests its normal package and dependency matrix on PHP 8.4 and later.
A separate PHP 8.5 CI job installs Tempest 3.18 and runs the bridge acceptance
test. This arrangement preserves the Greenlight PHP 8.4 package contract.

The bridge requires Tempest 3.18 or later in major version 3. This version has
the verified long-running kernel lifecycle that the bridge uses.

## Setup

Register the plugin in `greenlight.php` with the application root:

```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Tempest\TempestPlugin;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(new TempestPlugin(__DIR__));
```

The root directory must contain the application `composer.json` file and
`vendor` directory. Tempest uses this Composer metadata for discovery.

The bridge sets `ENVIRONMENT` to `testing` while a test uses Tempest. Tempest
then loads `.test.config.php` and `.testing.config.php` files. Pass
`environment:` to select a different environment.

The bridge restores the prior environment and global Tempest container after
each test.

The bridge also registers a new `GET /` Tempest request for each test. Tempest
uses this baseline request in its integration tests. The request lets framework
reset hooks resolve session services after a non-HTTP test. Application code can
replace the request binding during the test.

### Additional discovery locations

Tempest discovers application and package namespaces from Composer metadata.
Pass additional locations when test fixtures are outside these namespaces:

```php
use Tempest\Discovery\DiscoveryLocation;

new TempestPlugin(
    root: __DIR__,
    discoveryLocations: [
        new DiscoveryLocation('Tests\\Fixtures', __DIR__ . '/tests/Fixtures'),
    ],
);
```

The kernel adds these locations to normal Tempest discovery. Greenlight does
not replace or reproduce discovery.

## Container services

Declare application dependencies by type:

```php
final class RegistrationTest
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RegistrationHandler $handler,
    ) {}
}
```

Greenlight first resolves constructor parameters from its harness. It then asks
the Tempest container to resolve the type. Thus, `Doubles`, `TestChannel`, and
provider services take precedence over Tempest services.

Tempest can use discovered initializers and automatic constructor injection.
If resolution fails, the test reports a `TempestBridgeError` with the Tempest
container error as its cause.

### Tagged services

Use the native Tempest `#[Tag]` attribute for a tagged binding:

```php
use Tempest\Container\Tag;

public function __construct(
    #[Tag('archive')] private readonly Storage $storage,
) {}
```

The bridge gives the tag to the Tempest container. The resolved service must
have the declared parameter type.

### Kernel and container services

Greenlight supplies `Tempest\Core\Kernel` and
`Tempest\Container\Container` as per-run harness services:

```php
public function __construct(
    private readonly Kernel $kernel,
    private readonly Container $container,
) {}
```

These services expose the same kernel and container that resolve application
dependencies.

## State between tests

After each test, the bridge calls the Tempest long-running kernel shutdown
operation. Tempest performs these operations:

1. Dispatch `KernelEvent::SHUTTING_DOWN`.
2. Complete deferred tasks.
3. Dispatch `KernelEvent::RESETTING`.
4. Reset the container and each discovered `Resettable` implementation.
5. Dispatch `KernelEvent::RESET` and `KernelEvent::SHUTDOWN`.

The long-running mode prevents the shutdown operation from ending the worker.
The next test uses the reset container from the same kernel.

Use Tempest's `Resettable` interface for state that must not reach the next
test. State outside the container remains the test suite's responsibility.

The bridge does not isolate databases or other external services.

## Parallel resources

Each worker uses `.tempest/greenlight/<channel>` for Tempest internal storage.
This path prevents workers from writing to the same discovery and configuration
cache directory.

Greenlight also sets `GREENLIGHT_CHANNEL` in each worker process. Use this value
to separate databases, cache paths, queues, and other external resources.

If a service cannot use separate resources, mark the test class with
`#[RequiresResource]`. Configure the safe concurrency limit in `greenlight.php`.

## Non-goals

The current bridge does not supply these Tempest testing utilities:

* `IntegrationTest` or PHPUnit integration
* HTTP, console, mail, event, storage, or database tester objects
* database creation, migration, or transaction rollback
* replacement container bindings for Greenlight doubles

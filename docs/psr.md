# PSR applications

The PSR-11 bridge supplies container services to test constructors. It works
with each container that implements `Psr\Container\ContainerInterface`.

The bridge uses the packages that the application provides. Greenlight does
not declare a runtime dependency on a container implementation.

## Setup

Register `Psr11Plugin` with a factory that returns the application container:

```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Psr\Psr11Plugin;
use Psr\Container\ContainerInterface;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(new Psr11Plugin(
        static fn(): ContainerInterface => require __DIR__ . '/config/container.php',
    ));
```

The factory runs when a test first requests a container service. A worker does
not create the container when its tests do not use it.

The standard Mezzio configuration file, `config/container.php`, returns a
PSR-11 container. The same setup works with Laminas ServiceManager, PHP-DI,
and other PSR-11 implementations.

## Container services

Declare the dependency by type:

```php
final class RegistrationTest
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RegistrationHandler $handler,
    ) {}
}
```

Greenlight first resolves constructor parameters from its harness. It then
uses the PSR-11 container. Thus, built-in and plugin harness services have
precedence over container services.

The container must report the requested type from `has()`. The value from
`get()` must have that type. Greenlight reports a type mismatch before the test
runs.

### Services with another ID

Use `#[Service]` when the container ID differs from the parameter type:

```php
use Greenlight\Psr\Service;

public function __construct(
    #[Service('application.repository.users')]
    private readonly UserRepository $users,
) {}
```

Greenlight reports an error if an explicit ID does not exist. A missing
type-based ID lets the next service resolver try to supply the parameter.

### The container

Greenlight supplies `Psr\Container\ContainerInterface` as a harness service:

```php
public function __construct(private readonly ContainerInterface $container) {}
```

Use typed constructor dependencies for normal tests. Direct container access
is useful when a test verifies container configuration.

## State between tests

By default, the bridge discards the active container after each test attempt.
The next test creates a new container from the factory.

The bridge can run a reset callback before it discards the container:

```php
new Psr11Plugin(
    static fn(): ContainerInterface => require __DIR__ . '/config/container.php',
    reset: static function (ContainerInterface $container): void {
        $container->get(ApplicationResetter::class)->reset();
    },
);
```

The callback runs only after a test creates the container. The bridge discards
the container even when the callback fails.

For a container that can reset all service state, keep one container for each
worker:

```php
new Psr11Plugin(
    static fn(): ContainerInterface => require __DIR__ . '/config/container.php',
    refreshBetweenTests: false,
    reset: static function (ContainerInterface $container): void {
        $container->get(ApplicationResetter::class)->reset();
    },
);
```

Do not disable refreshes when shared services can keep state. Tests on the same
worker will receive the same service instances.

The bridge controls only container state. Isolation for databases, caches,
queues, files, and other external resources remains the test suite's
responsibility.

## Parallel resources

Greenlight sets `GREENLIGHT_CHANNEL` in every worker process. Use the channel
in application configuration to select worker-specific databases and similar
resources.

If a resource cannot use channels, protect its test classes with
`#[RequiresResource]`. See [configuration](configuration.md) for the complete
resource rules.

## Non-goals

The bridge does not provide these features:

* Container auto-discovery
* Container service replacement
* HTTP request construction
* Browser or network requests
* Database creation, migration, or transaction control
* Framework-specific helpers or fakes

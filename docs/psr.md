# PSR-11 containers

The PSR-11 bridge supplies services from a
[PSR-11 container](https://www.php-fig.org/psr/psr-11/) to test constructors. A
PSR-11 container implements `Psr\Container\ContainerInterface`.

Greenlight uses the container packages from the application. It does not
install a container implementation.

## Setup

Register `Psr11Plugin` with a factory that returns the application container:

<!-- php-example {"example":"psr-example-01","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Psr\Psr11Plugin;
use Psr\Container\ContainerInterface;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(static fn(): Psr11Plugin => new Psr11Plugin(
        static fn(): ContainerInterface => require __DIR__ . '/config/container.php',
    ));
```

The factory runs when a test first requests a container service. A worker does
not create the container when its tests do not use it.

[Mezzio](https://docs.mezzio.dev/mezzio/) is a framework for PSR-15 middleware
applications. Its standard `config/container.php` file returns a PSR-11
container.

The plugin also works with
[Laminas ServiceManager](https://docs.laminas.dev/laminas-servicemanager/),
[PHP-DI](https://php-di.org/), and other PSR-11 implementations.

## Container services

Declare the dependency by type:

<!-- php-example {"example":"psr-example-02","file":"snippet.php","mode":"file","tools":["rector"]} -->
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
uses the PSR-11 container. Harness services have precedence over container
services.

Container creation, reset, and service-resolution failures throw
`ServiceResolutionFailed`. Concrete PSR-11 bridge exceptions are internal.

For an available service ID, the container returns `true` from `has()`. The
value from `get()` has the declared parameter type. Greenlight reports a type
mismatch before the test runs.

### Services with a different ID

Use `#[Service]` when the container ID differs from the parameter type:

<!-- php-example {"example":"psr-example-03","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
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

<!-- php-example {"example":"psr-example-04","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
public function __construct(private readonly ContainerInterface $container) {}
```

Use typed constructor dependencies for application services. Use direct
container access only to verify container configuration.

## State between tests

By default, the bridge discards the active container after each test attempt.
The next test creates a new container from the factory.

Use `reset:` to reset container state before the bridge discards the container:

<!-- php-example {"example":"psr-example-05","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
new Psr11Plugin(
    static fn(): ContainerInterface => require __DIR__ . '/config/container.php',
    reset: static function (ContainerInterface $container): void {
        $container->get(ApplicationResetter::class)->reset();
    },
);
```

The callback runs only if a test creates the container. The bridge discards the
container even if the callback throws.

If services keep no test state or `reset:` removes all state, set
`refreshBetweenTests` to `false`. The worker then keeps one container:

<!-- php-example {"example":"psr-example-06","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
new Psr11Plugin(
    static fn(): ContainerInterface => require __DIR__ . '/config/container.php',
    refreshBetweenTests: false,
    reset: static function (ContainerInterface $container): void {
        $container->get(ApplicationResetter::class)->reset();
    },
);
```

Tests on the same worker receive the same service instances.

The bridge controls only container state. It does not isolate databases,
caches, queues, files, or other external resources.

## Parallel resources

Greenlight sets `GREENLIGHT_CHANNEL` in every worker process. Use the channel
in application configuration to select worker-specific databases and similar
resources.

If workers cannot use separate external resources, use `#[RequiresResource]`.
See [configuration](configuration.md) for concurrency limits.

## Unsupported features

The bridge does not provide:

* Container auto-discovery
* Replacement of container services with doubles
* HTTP request execution. Use the [PSR-15 harness](psr15.md) for HTTP requests.
* Browser or network requests
* Database creation, migration, or transaction control
* Framework-specific helpers or fakes

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
use Greenlight\Psr11\Psr11Plugin;
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

Without an explicit service source, Greenlight first resolves constructor
parameters from its harness. It then uses the PSR-11 container. Harness
services have precedence over container services.

Container creation, reset, and service-resolution failures throw
`ServiceResolutionFailed`. Concrete PSR-11 bridge exceptions are internal.

For an available service ID, the container returns `true` from `has()`. The
value from `get()` has the declared parameter type. Greenlight reports a type
mismatch before the test runs.

### Services with a different ID

Use `#[Service]` when the container ID differs from the parameter type:

<!-- php-example {"example":"psr-example-03","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
use Greenlight\Harness\Service;

public function __construct(
    #[Service('application.repository.users')]
    private readonly UserRepository $users,
) {}
```

Greenlight reports an error if an explicit ID does not exist. Without an
explicit source or ID, a missing type-based ID lets the next resolver try to
supply the parameter.

### The container

Greenlight supplies `Psr\Container\ContainerInterface` as a harness service:

<!-- php-example {"example":"psr-example-04","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
public function __construct(private readonly ContainerInterface $container) {}
```

Use typed constructor dependencies for application services. Use direct
container access only to verify container configuration.

### Multiple containers

Give each plugin instance a unique `source:` name:

<!-- php-example {"example":"psr-example-07","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Psr11\Psr11Plugin;
use Psr\Container\ContainerInterface;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(
        static fn(): Psr11Plugin => new Psr11Plugin(
            static fn(): ContainerInterface => require __DIR__ . '/billing/container.php',
            source: 'billing',
        ),
        static fn(): Psr11Plugin => new Psr11Plugin(
            static fn(): ContainerInterface => require __DIR__ . '/legacy/container.php',
            source: 'legacy',
        ),
    );
```

Select a source on each parameter that needs a specific container:

<!-- php-example {"example":"psr-example-08","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
use Greenlight\Harness\Service;
use Psr\Container\ContainerInterface;

public function __construct(
    #[Service(source: 'billing')]
    private readonly UserRepository $billingUsers,
    #[Service('users.repository', source: 'legacy')]
    private readonly UserRepository $legacyUsers,
    #[Service(source: 'legacy')]
    private readonly ContainerInterface $legacyContainer,
) {}
```

Without `id:`, the attribute requests the declared parameter type. An explicit
ID requests that ID from the selected container. Both forms check the returned
service type.

A source request uses only the selected plugin instance. An unknown source or
absent service causes an error without a request to another container. Source
selection also takes precedence over global harness services.

Each named plugin supplies its own `ContainerInterface` harness definition. If
multiple named definitions match, an unqualified container parameter causes an
ambiguity error. Use `source:` to select the required container.

Parameters without `source:` retain normal harness and resolver order. Named
container resolvers still participate in that order. An unqualified explicit
ID does not select a container.

Source names are case sensitive, nonempty, and unique among plugin instances.
See [service sources](plugins.md#servicesource) for custom providers and
resolvers.

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

With the default refresh, the callback runs only after an attempt that creates
the container. The bridge discards the container even if the callback throws.

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

Tests on the same worker share the container. The container controls whether
each service request returns an existing instance or a new instance.

When the shared container is active, the callback runs after each test attempt.
This includes an attempt that does not request the container.

If the reset callback throws, the attempt has an error and the bridge discards
the shared container. The next service request calls the container factory
again. Thus, later tests receive services from a new container.

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

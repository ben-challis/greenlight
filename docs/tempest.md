# Tempest applications

The [Tempest](https://tempestphp.com/) bridge supplies Tempest container
services to test constructors.

The bridge boots a Tempest kernel when a test first requests a Tempest service.
The kernel stays active for the worker lifetime. Tempest controls discovery,
configuration, deferred tasks, container reset, and shutdown events.

## Compatibility

Greenlight requires PHP 8.4 or later. Greenlight has no Tempest runtime
dependency. The bridge supports Tempest 3.18 and later releases in major
version 3.

Tempest 3.18 requires PHP 8.5 or later. Use PHP 8.5 or later with the bridge.
Install Tempest in the application:

```console
composer require tempest/framework:^3.18
```

## Setup

Register the plugin in `greenlight.php` with the application root:

<!-- php-example {"example":"tempest-example-01","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Tempest\TempestPlugin;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(static fn(): TempestPlugin => new TempestPlugin(__DIR__));
```

Use the directory that contains the application `composer.json` file and the
`vendor` directory. Tempest reads the Composer metadata during discovery.

The bridge sets `ENVIRONMENT` to `testing` while Tempest is active for a test.
Tempest then loads `.test.config.php` and `.testing.config.php` files. Pass
`environment:` to select a different value.

The bridge restores the previous `ENVIRONMENT` value and global Tempest
container after each test.

The bridge adds a new Tempest `GET /` request to the container for each test.
This request lets Tempest reset hooks resolve session services after a non-HTTP
test. A test can replace this request in the container.

### Additional discovery locations

Tempest discovers application and package namespaces from Composer metadata.
Pass additional locations when test fixtures are outside these namespaces:

<!-- php-example {"example":"tempest-example-02","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Tempest\Discovery\DiscoveryLocation;

new TempestPlugin(
    root: __DIR__,
    discoveryLocations: [
        new DiscoveryLocation('Tests\\Fixtures', __DIR__ . '/tests/Fixtures'),
    ],
);
```

Tempest adds these locations during discovery.

## Container services

Declare application dependencies by type:

<!-- php-example {"example":"tempest-example-03","file":"snippet.php","mode":"file","tools":["rector"]} -->
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
fallback-capable service resolvers. Finally, it asks the Tempest container to
resolve the type. Greenlight always places the terminal Tempest resolver last.
Registration order and plugin priority do not change this rule.

Tempest can use discovered initializers and automatic constructor injection. If
Tempest cannot resolve the type, Greenlight throws `ServiceResolutionFailed`.
The exception contains the Tempest container exception as its cause.

### Tagged services

Use the Tempest `#[Tag]` attribute to select a tagged service:

<!-- php-example {"example":"tempest-example-04","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
use Tempest\Container\Tag;

public function __construct(
    #[Tag('archive')] private readonly Storage $storage,
) {}
```

The bridge passes the tag to the Tempest container. The resolved service has
the declared parameter type.

### Kernel and container services

Greenlight supplies `Tempest\Core\Kernel` and
`Tempest\Container\Container` as harness services for each worker:

<!-- php-example {"example":"tempest-example-05","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
public function __construct(
    private readonly Kernel $kernel,
    private readonly Container $container,
) {}
```

## State between tests

After each test, the bridge calls `Kernel::shutdown()`. Tempest performs these
operations:

1. Dispatch `KernelEvent::SHUTTING_DOWN`.
2. Complete deferred tasks.
3. Dispatch `KernelEvent::RESETTING`.
4. Reset the container and each discovered `Resettable` implementation.
5. Dispatch `KernelEvent::RESET` and `KernelEvent::SHUTDOWN`.

The call does not end the worker. If shutdown succeeds, the next test uses the
reset container from the same kernel. If shutdown throws, the attempt has an
error and the bridge discards the active kernel. It restores the previous
process environment and global container. The next container request boots a
new kernel.

Use Tempest's `Resettable` interface to reset container state after each test.
Reset state outside the container in an `#[After]` hook.

## Parallel resources

Each worker uses `.tempest/greenlight/<channel>` for Tempest internal storage.
This path gives each worker a separate discovery and configuration cache.

Greenlight sets `GREENLIGHT_CHANNEL` in each worker process. Use this value to
assign separate databases, cache paths, queues, and other external resources.

If workers cannot use separate external resources, use `#[RequiresResource]`.
Configure the safe concurrency limit in `greenlight.php`. See
[configuration](configuration.md) for concurrency limits.

# Testing Symfony applications

The Symfony bridge lets tests receive Symfony services through constructor
injection, alongside Greenlight's built-in harness services.

The bridge boots your kernel once per worker. It lives in the
`Greenlight\Symfony` namespace, ships with Greenlight, and does nothing unless
you register it. Symfony remains a dev dependency of your application; it is
not required by Greenlight itself.

## Setup

Register the plugin in `greenlight.php` with your kernel class:

```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Symfony\SymfonyPlugin;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(new SymfonyPlugin(App\Kernel::class, env: 'test', debug: false));
```

Use a closure when the kernel needs custom construction:

```php
new SymfonyPlugin(static fn(): KernelInterface => new App\Kernel('test', false));
```

The kernel boots lazily the first time a test asks for a container service, then
stays alive for the lifetime of the worker. Workers whose tests never use the
container do not boot Symfony.

The bridge is tested with `symfony/framework-bundle` 6.4, 7.x, and 8.x. At
boot, it requires the kernel's container to expose `test.service_container`;
with service resets enabled, it also requires `services_resetter`. A standard
FrameworkBundle test environment provides the test container when
`framework.test` is enabled.

At boot, the bridge checks that the container supports the features it needs.
Missing the Symfony test container, or missing `services_resetter` while service
resets are enabled, is treated as a configuration error with a fix in the
message. The bridge does not silently fall back to weaker isolation.

## Injecting services

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

Greenlight resolves constructor parameters from its own harness first, then from
the Symfony container. That means services such as `Doubles`, `TestChannel`, and
provider services take precedence over container services.

When neither side can resolve a type, the test fails and reports both misses.

Symfony's usual test-container rules still apply. A private service must be
referenced somewhere in the container to survive compilation. A service that is
unused and removed during compilation cannot be injected.

### Services without a usable type

Some services cannot be selected by type alone: string-id-only services,
interfaces with multiple implementations, and decorated services are common
examples. Use `#[Service]` to name the service explicitly:

```php
use Greenlight\Symfony\Service;

public function __construct(
    #[Service('mailer.transports.async')] private readonly TransportInterface $transport,
) {}
```

The parameter type is still checked. If the named service is not an instance of
the declared type, the test fails instead of receiving the wrong object.

### The kernel itself

`KernelInterface` is available as a per-run harness service for tests that need
to inspect boot parameters or the container directly:

```php
public function __construct(private readonly KernelInterface $kernel) {}
```

## State between tests

The kernel is not rebooted between tests.

After each test, the bridge calls Symfony's `services_resetter`, the same reset
mechanism Symfony uses between requests. Services tagged with `kernel.reset` are
reset, including services autoconfigured from `ResetInterface`. This also covers
common Symfony services such as Doctrine's `ManagerRegistry`, cache pools, and
the profiler. Any stateful service of your own that must not leak between tests
should implement `ResetInterface`.

The resetter is captured and checked when the kernel boots. If resets are
enabled and the container does not provide a resetter, every test fails rather
than running with shared, unreset state.

For a container that genuinely has no stateful services, pass
`resetBetweenTests: false` to the plugin. This explicitly disables the resetter
requirement. Do not use it with services that keep state: tests running on the
same worker will then share those service instances.

The bridge boots and resets the Symfony kernel. Isolation for databases and
other external services remains the test suite's responsibility.

## Parallel resources

Workers run tests at the same time, so shared external resources need to be
split per worker or protected by a concurrency limit.

Greenlight sets `GREENLIGHT_CHANNEL` in every worker process. It is a stable
number from 1 to the worker count, and no two concurrent tests use the same
channel. Use it in normal Symfony configuration to key shared resources:

```yaml
# config/packages/test/doctrine.yaml
doctrine:
    dbal:
        dbname: 'app_test_%env(default:fallback_channel:GREENLIGHT_CHANNEL)%'

parameters:
    env(fallback_channel): '1'
```

The same pattern works for cache directories, upload paths, message transport
names, and similar resources.

Creating and migrating per-channel databases is still the application's job.
Use a loop in the test bootstrap, a Makefile target, or another project-level
setup step. Channel numbers remain stable across worker recycling, so those
schemas can live for the whole test run.

If a service cannot be split per channel, mark the classes that use it with
`#[RequiresResource]` and configure its safe concurrency:

```php
#[RequiresResource('payments-sandbox')]
final class PaymentGatewayTest { ... }
```

```php
return GreenlightConfig::create()
    ->resourceLimit('payments-sandbox', 2);
```

The limit controls how many matching classes run. It does not choose a service
instance, and it does not coordinate another Greenlight process or CI shard.
See [configuration](configuration.md) for the complete resource rules.

## Doubles and the container

The bridge only injects real Symfony container services.

There is no API for replacing a container service with a double. Tests that need
a doubled collaborator should get the double through `Doubles` and construct the
subject under test directly. That keeps the double's lifecycle and verification
under Greenlight's control.

Container-level replacement may be added later, but only if there is a design
that avoids guessy service swapping.

## Non-goals

The current bridge does not cover:

* HTTP request/response testing with `KernelBrowser`
* transaction rollback isolation
* dotenv loading
* kernel auto-discovery
* database creation or migration tooling
* Messenger assertions

The bridge is intentionally small: it provides the kernel, container services,
service resets, and worker channels.

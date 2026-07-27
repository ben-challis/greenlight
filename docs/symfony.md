# Symfony applications

The Symfony bridge supplies Symfony services to test constructors. It supplies
these services together with the built-in Greenlight harness services.

The bridge boots the application kernel once for each worker. Register the
plugin to activate the bridge. The bridge uses the Symfony packages that the
application provides. Greenlight does not declare a runtime dependency on
Symfony.

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

The kernel boots when a test first requests a container service. It remains
active for the worker lifetime. A worker does not boot Symfony when its tests do
not use the container.

Greenlight tests the bridge with `symfony/framework-bundle` 6.4, 7.x, and 8.x.
At boot, the bridge requires the kernel container to expose
`test.service_container`. When service resets are active, it also requires
`services_resetter`. A standard FrameworkBundle test environment provides the
test container when `framework.test` is active.

At boot, the bridge checks that the container supports the features it needs.
The bridge reports a configuration error if the Symfony test container is not
present. It also reports a configuration error if an active service reset has
no `services_resetter`. The error message contains a correction. The bridge
does not silently use weaker isolation.

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

Greenlight first resolves constructor parameters from its harness. It then uses
the Symfony container. Thus, `Doubles`, `TestChannel`, and provider services
take precedence over container services.

When neither side can resolve a type, the test fails and reports both misses.

The normal Symfony test-container rules still apply. The container must
reference a private service to retain it during compilation. The Symfony
compiler can remove an unused service. Greenlight cannot inject a removed
service.

### Services without a usable type

Type alone cannot select some services. Examples include string-ID-only
services, interfaces with multiple implementations, and decorated services.
Use `#[Service]` to name the service explicitly:

```php
use Greenlight\Symfony\Service;

public function __construct(
    #[Service('mailer.transports.async')] private readonly TransportInterface $transport,
) {}
```

Greenlight still checks the parameter type. If the named service is not an
instance of the declared type, the test fails and does not receive the object.

### The kernel itself

Greenlight supplies `KernelInterface` as a per-run harness service. Tests can
use it to inspect boot parameters or the container directly:

```php
public function __construct(private readonly KernelInterface $kernel) {}
```

## State between tests

The bridge keeps the kernel active between tests.

After each test, the bridge calls Symfony's `services_resetter`, the same reset
mechanism Symfony uses between requests. The resetter resets services with the
`kernel.reset` tag. This includes services that Symfony configures from
`ResetInterface`. It also includes common Symfony services such as Doctrine's
`ManagerRegistry`, cache pools, and the profiler. Implement `ResetInterface` for
each stateful service that must keep tests isolated.

The bridge captures and checks the resetter when the kernel boots. If service
resets are active without a container resetter, every test fails. No test runs
with shared state that has not had a reset.

For a container that has no stateful services, pass `resetBetweenTests: false`
to the plugin. This value disables the resetter requirement. Do not use this
value with services that keep state. Tests on the same worker will share those
service instances.

The bridge boots and resets the Symfony kernel. Isolation for databases and
other external services remains the test suite's responsibility.

## Parallel resources

Workers run tests at the same time. Split shared external resources for each
worker. Alternatively, protect them with a concurrency limit.

Greenlight sets `GREENLIGHT_CHANNEL` in every worker process. It is a stable
number from 1 through the worker count, and no two concurrent tests use the same
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

The application must create and migrate databases for each channel. Use a loop
in the test bootstrap, a Makefile target, or another project-level setup step.
Channel numbers remain stable after a worker recycle. Thus, these schemas can
remain for the complete test run.

If a service cannot be split per channel, mark the classes that use it with
`#[RequiresResource]`. Configure its safe concurrency:

```php
#[RequiresResource('payments-sandbox')]
final class PaymentGatewayTest { ... }
```

```php
return GreenlightConfig::create()
    ->resourceLimit('payments-sandbox', 2);
```

The limit controls how many classes that require this resource can run. It does
not choose a service instance, and it does not coordinate another Greenlight
process or CI shard. See [configuration](configuration.md) for the complete
resource rules.

## Doubles and the container

The bridge injects only real Symfony container services.

There is no API that replaces a container service with a double. If a test needs
a doubled collaborator, get the double through `Doubles`. Then construct the
test subject directly. Greenlight then controls the double lifecycle and
verification.

Container-level replacement remains a possible future addition. Its design must
prevent uncertain service replacement.

## Non-goals

The current bridge does not cover:

* HTTP request and response tests with `KernelBrowser`
* transaction rollback isolation
* dotenv file load
* kernel auto-discovery
* database creation or migration tools
* Messenger assertions

The bridge has a deliberately small scope. It provides the kernel, container
services, service resets, and worker channels.

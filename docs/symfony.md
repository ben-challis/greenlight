# Symfony applications

The [Symfony](https://symfony.com/) bridge supplies Symfony services and
built-in Greenlight harness services to test constructors.

The bridge boots the application kernel once for each worker. Register the
plugin to activate the bridge. The bridge uses the Symfony packages that the
application provides. Greenlight does not declare a runtime dependency on
Symfony.

## Setup

Register the plugin in `greenlight.php` with your kernel class:

<!-- php-example {"example":"symfony-example-01","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Symfony\SymfonyPlugin;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(static fn(): SymfonyPlugin => new SymfonyPlugin(App\Kernel::class, env: 'test', debug: false));
```

Use a closure when the kernel needs custom construction:

<!-- php-example {"example":"symfony-example-02","file":"snippet.php","mode":"statements","tools":["rector"]} -->
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

If a required container service is absent, the bridge reports a configuration
error with a correction. It does not use weaker isolation.

## Container services

Declare the dependency by type:

<!-- php-example {"example":"symfony-example-03","file":"snippet.php","mode":"file","tools":["rector"]} -->
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

Bridge setup and service-resolution failures throw `ServiceResolutionFailed`.
Concrete Symfony bridge exceptions are internal.

The normal Symfony test-container rules still apply. The container must
reference a private service to retain it during compilation. The Symfony
compiler can remove an unused service. Greenlight cannot inject a removed
service.

### Services without a usable type

Type alone cannot select some services. Examples include string-ID-only
services, interfaces with multiple implementations, and decorated services.
Use `#[Service]` to name the service explicitly:

<!-- php-example {"example":"symfony-example-04","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
use Greenlight\Harness\Service;

public function __construct(
    #[Service('mailer.transports.async')] private readonly TransportInterface $transport,
) {}
```

Greenlight still checks the parameter type. If the named service is not an
instance of the declared type, the test fails and does not receive the object.

### The kernel itself

Greenlight supplies `KernelInterface` as a per-worker harness service. Tests can
use it to inspect boot parameters or the container directly:

<!-- php-example {"example":"symfony-example-05","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
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
resets are active without a container resetter, every test fails.

For a container that has no stateful services, pass `resetBetweenTests: false`
to the plugin. This value disables the resetter requirement. Do not use this
value with services that keep state. Tests on the same worker will share those
service instances.

The bridge does not isolate databases or other external services.

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
    fallback_channel: '1'
```

The `default:` processor reads the `fallback_channel` container parameter when
`GREENLIGHT_CHANNEL` is absent. See the Symfony
[environment variable processors](https://symfony.com/doc/current/configuration/env_var_processors.html).

The same pattern works for cache directories, upload paths, message transport
names, and similar resources.

The application must create and migrate databases for each channel. Use a loop
in the test bootstrap, a Makefile target, or another project-level setup step.
Channel numbers remain stable after a worker crash. Thus, these schemas can
remain for the complete test run.

If you cannot split a service per channel, mark the classes that use it with
`#[RequiresResource]`. Configure its safe concurrency:

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
```php
#[RequiresResource('payments-sandbox')]
final class PaymentGatewayTest { ... }
```

<!-- php-example {"example":"symfony-example-07","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
return GreenlightConfig::create()
    ->resourceLimit('payments-sandbox', 2);
```

The limit controls how many assignments that require this resource can run. It
does not choose a service instance, and it does not coordinate another
Greenlight process or CI shard. See [configuration](configuration.md) for the
complete resource rules.

## Doubles and the container

The bridge does not replace container services with doubles. If a test needs a
doubled collaborator, get the double through `Doubles`. Then construct the test
subject directly. Greenlight then controls the double lifecycle and
verification.

## Non-goals

The current bridge does not cover:

* HTTP request and response tests with `KernelBrowser`
* transaction rollback isolation
* dotenv file load
* kernel auto-discovery
* database creation or migration tools
* Messenger assertions

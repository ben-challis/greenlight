# Testing Symfony applications

The Symfony bridge boots your kernel once per worker and makes container
services constructor-injectable in tests, alongside the built-in harness
services. It lives in the `Greenlight\Symfony` namespace, ships inside
Greenlight, and loads nothing unless you register it; Symfony stays a
dev-time dependency of your application, not of Greenlight.

## Setup

Register the plugin in `greenlight.php` with your kernel class:

```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Symfony\SymfonyPlugin;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(new SymfonyPlugin(App\Kernel::class, env: 'test', debug: false));
```

A closure works when construction is exotic:

```php
new SymfonyPlugin(static fn(): KernelInterface => new App\Kernel('test', false));
```

The kernel boots lazily on first use and lives for the worker process
lifetime. Nothing boots in workers whose tests never touch a container
service.

Requirements: `symfony/framework-bundle` 6.4, 7.x, or 8.x in your project,
and a kernel environment with `framework.test: true` (the standard `test`
environment). Without the test container, private services are unreachable
and explicit-id lookups fail with a hint saying exactly that.

## Injecting services

Declare the type; the bridge resolves anything the container knows:

```php
final class RegistrationTest
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RegistrationHandler $handler,
    ) {}
}
```

Resolution order is harness services first (so `Doubles`,
`TestChannel`, and provider services always win), then the Symfony
container. A type neither side knows fails the test with both misses named.

As in Symfony's own test container, a private service must be referenced
somewhere in the container to survive compilation; a service nothing uses
is removed and cannot be injected.

### Services without a usable type

When the type alone cannot pick the service (string-id-only registrations,
interfaces with several implementations, decorated services), override the
lookup with `#[Service]`:

```php
use Greenlight\Symfony\Service;

public function __construct(
    #[Service('mailer.transports.async')] private readonly TransportInterface $transport,
) {}
```

The declared parameter type is still enforced: a container service that is
not an instance of it fails loudly rather than injecting a surprise.

### The kernel itself

`KernelInterface` is a per-run harness service, for the rare test that
inspects boot parameters or the container directly:

```php
public function __construct(private readonly KernelInterface $kernel) {}
```

## State between tests

The kernel is not rebooted between tests. Instead, after every test the
bridge invokes Symfony's `services_resetter`, the same mechanism the
framework uses between requests: every service tagged `kernel.reset`
(autoconfigured for `ResetInterface` implementations, and covering
Doctrine's `ManagerRegistry`, cache pools, and the profiler out of the box)
is reset. A stateful service of your own that must not leak across tests
should implement `ResetInterface`.

There is no transaction-per-test wrapping and none is planned: it breaks
any test crossing a process or connection boundary. Data written by a test
stays written; design tests to own their data.

## Parallel isolation

Workers run tests concurrently, so external resources need one copy per
worker. Greenlight exports `GREENLIGHT_CHANNEL` into every worker process:
a stable slot from 1 to the worker count, never shared by two concurrent
tests (see the channel notes in [getting started](getting-started.md)).
Key every shared resource off it in ordinary Symfony configuration:

```yaml
# config/packages/test/doctrine.yaml
doctrine:
    dbal:
        dbname: 'app_test_%env(default:fallback_channel:GREENLIGHT_CHANNEL)%'

parameters:
    env(fallback_channel): '1'
```

The same pattern covers cache directories, upload paths, and message
transport names. Creating and migrating the per-channel databases stays
your job (a loop over channels in the test bootstrap or a Makefile target);
channel numbers are stable across worker recycling, so schemas persist for
the whole run.

## Doubles and the container

The bridge injects real container services only. There is deliberately no
"replace this container service with a double" API: a test that needs a
doubled collaborator should take the double through `Doubles` and construct
the subject with it, keeping the double's lifecycle verification intact.
Container-level replacement may come later if a shape emerges that does not
reintroduce guess-friendly service swapping.

## Non-goals of the current bridge

HTTP request/response testing (KernelBrowser), transaction-rollback
isolation, dotenv loading and kernel auto-discovery, database
creation/migration tooling, and Messenger assertions. The bridge stays a
thin, honest layer: kernel, container services, reset, channels.

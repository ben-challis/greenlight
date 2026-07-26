# Laravel applications

The Laravel bridge supplies Laravel container services to test constructors. It
supplies these services together with the built-in Greenlight harness services.

The bridge boots a fresh application for each test that uses it. The bridge is
in the `Greenlight\Laravel` namespace and is part of Greenlight. Register it to
activate it. Laravel remains a development dependency of your application.
Greenlight does not require Laravel.

## Setup

Register the plugin in `greenlight.php` with your application bootstrap file:

```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Laravel\LaravelPlugin;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(new LaravelPlugin(__DIR__ . '/bootstrap/app.php'));
```

The bootstrap file is the standard Laravel entry point. It returns the result
of `Application::configure(...)->create()`. Use a closure when the application
needs custom construction:

```php
new LaravelPlugin(static fn(): Application => Application::configure(basePath: __DIR__)
    ->withProviders([App\Providers\AppServiceProvider::class])
    ->create());
```

The plugin sets `APP_ENV` before each boot. The default value is `testing`.
Pass `env:` to select another environment. Laravel loads `.env` without a
change to variables that already exist, so the plugin value wins. When a
`.env.testing` file exists, Laravel prefers it over `.env`.

The application boots when a test first requests a container service. A worker
does not boot Laravel when its tests do not use the container. Greenlight tests
the bridge with `laravel/framework` 13.

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
the Laravel container. Thus, `Doubles`, `TestChannel`, and provider services
take precedence over container services.

When neither side can resolve a type, the test fails and reports both misses.

The bridge resolves bound services only. Laravel can construct an unbound class
through implicit resolution, but the bridge does not use that mechanism. Bind
the service in a service provider to make it injectable.

### Services without a usable type

Type alone cannot select some services. Examples include string-id-only
services, interfaces with multiple implementations, and aliased services. Use
`#[Service]` to name the service explicitly:

```php
use Greenlight\Laravel\Service;

public function __construct(
    #[Service('cache.store')] private readonly Repository $cache,
) {}
```

Greenlight still checks the parameter type. If the named service is not an
instance of the declared type, the test fails and does not receive the object.

### The application itself

Greenlight supplies `Illuminate\Contracts\Foundation\Application` as a harness
service. The service scope is per-test, or per-run when `refreshBetweenTests`
is false. Tests can use it to inspect the environment or the container
directly:

```php
public function __construct(private readonly Application $app) {}
```

The concrete `Illuminate\Foundation\Application` type, the container contract,
and the PSR-11 container interface also resolve. Laravel aliases them to the
application in its own container.

## State between tests

The bridge discards the application after each test.

Each test that uses container services receives a fresh boot. This is the same
isolation model as Laravel's own test harness. Configuration changes, facade
roots, singleton state, and container registrations from one test cannot reach
the next test. The bridge also clears Laravel's static facade and container
references after each test.

Laravel installs global error and exception handlers during boot. The bridge
restores the previous handlers immediately after boot, so Greenlight keeps
ownership of test diagnostics.

Static state outside the container remains the test suite's responsibility. An
example is `Carbon::setTestNow()`. Reset such state in an `#[After]` hook.

For a container that has no stateful services, pass `refreshBetweenTests:
false` to the plugin. The application then boots once for each worker and no
reset occurs. Do not use this value with services that keep state. Tests on the
same worker will share those service instances.

The bridge boots and discards the Laravel application. Isolation for databases
and other external services remains the test suite's responsibility.

## Parallel resources

Workers run tests at the same time. Split shared external resources for each
worker. Alternatively, protect them with a concurrency limit.

Greenlight sets `GREENLIGHT_CHANNEL` in every worker process. It is a stable
number from 1 through the worker count, and no two concurrent tests use the same
channel. Use it in normal Laravel configuration to key shared resources:

```php
// config/database.php
'database' => env('DB_DATABASE', 'app') . '_test_' . env('GREENLIGHT_CHANNEL', '1'),
```

The same pattern works for cache prefixes, storage paths, queue names, and
similar resources.

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

The bridge injects only real Laravel container services.

There is no API that replaces a container service with a double. If a test needs
a doubled collaborator, get the double through `Doubles`. Then construct the
test subject directly. Greenlight then controls the double lifecycle and
verification.

Container-level replacement remains a possible future addition. Its design must
prevent uncertain service replacement.

## Non-goals

The current bridge does not cover:

* HTTP request and response tests
* Laravel `TestCase` helpers and fakes for queues, mail, and events
* `RefreshDatabase` and transaction rollback isolation
* database creation or migration tools
* bootstrap file auto-discovery
* Dusk browser tests

The bridge has a deliberately small scope. It provides the application,
container services, per-test refreshes, and worker channels.

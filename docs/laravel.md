# Laravel applications

The [Laravel](https://laravel.com/) bridge supplies Laravel container services
and built-in Greenlight harness services to test constructors.

By default, the bridge boots a fresh application for each test attempt that
uses it. Register the plugin to activate the bridge. The bridge uses the
Laravel package that the application provides. Greenlight does not declare a
runtime dependency on Laravel.

## Setup

Register the plugin in `greenlight.php` with your application bootstrap file:

<!-- php-example {"example":"laravel-example-01","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Laravel\LaravelPlugin;

return GreenlightConfig::create()
    ->paths(['tests'])
    ->plugins(static fn(): LaravelPlugin => new LaravelPlugin(__DIR__ . '/bootstrap/app.php'));
```

The bootstrap file is the standard Laravel entry point. It returns the result
of `Application::configure(...)->create()`. Use a closure when the application
needs custom construction:

<!-- php-example {"example":"laravel-example-02","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
new LaravelPlugin(static fn(): Application => Application::configure(basePath: __DIR__)
    ->withProviders([App\Providers\AppServiceProvider::class])
    ->create());
```

The plugin sets `APP_ENV` while the application is active. The default value is
`testing`. Pass `env:` to select another environment. Laravel loads `.env`
without a change to variables that already exist, so the plugin value wins.
When a `.env.testing` file exists, Laravel prefers it over `.env`.

The plugin restores the previous `APP_ENV` value after it discards the
application. If you disable refreshes, the selected value stays active for the
worker lifetime.

The application boots when a test first requests a container service. A worker
does not boot Laravel when its tests do not use the container.

The bridge requires the complete `laravel/framework` 13 package instead of
individual `illuminate/*` components.

## Container services

Declare the dependency by type:

<!-- php-example {"example":"laravel-example-03","file":"snippet.php","mode":"file","tools":["rector"]} -->
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

Bridge setup and service-resolution failures throw `ServiceResolutionFailed`.
Concrete Laravel bridge exceptions are internal.

The bridge resolves bound services only. Laravel can construct an unbound class
through implicit resolution, but the bridge does not use that mechanism. Bind
the service in a service provider to make it injectable.

### Services without a usable type

Type alone cannot select some services. Examples include string-id-only
services, interfaces with multiple implementations, and aliased services. Use
`#[Service]` to name the service explicitly:

<!-- php-example {"example":"laravel-example-04","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
use Greenlight\Harness\Service;

public function __construct(
    #[Service('cache.store')] private readonly Repository $cache,
) {}
```

Greenlight still checks the parameter type. If the named service is not an
instance of the declared type, the test fails and does not receive the object.

### The application itself

Greenlight supplies `Illuminate\Contracts\Foundation\Application` as a harness
service. The service scope is per-test, or per-worker when `refreshBetweenTests`
is false. Tests can use it to inspect the environment or the container
directly:

<!-- php-example {"example":"laravel-example-05","file":"snippet.php","mode":"class-members","tools":["rector"]} -->
```php
public function __construct(private readonly Application $app) {}
```

The concrete `Illuminate\Foundation\Application` type, the container contract,
and the PSR-11 container interface also resolve. Laravel aliases them to the
application in its own container.

## State between tests

By default, the bridge discards the application after each test attempt.
Configuration changes, facade roots, singleton state, and container
registrations cannot reach the next attempt.

This scope is smaller than the isolation in Laravel's `TestCase`. Laravel's
test harness resets framework static state for its helpers and fakes. The
bridge does not supply these features.

Laravel normally installs process-global diagnostic handlers during boot. The
bridge disables this Laravel bootstrapper, so Greenlight keeps ownership of
test diagnostics. The bridge also clears Laravel framework state that can
retain a discarded application.

Static state outside the application container remains the test suite's
responsibility. An example is `Carbon::setTestNow()`. Reset such state in an
`#[After]` hook.

For a container that has no stateful services, pass `refreshBetweenTests:
false` to the plugin. The application then boots once for each worker and no
reset occurs. Do not use this value with services that keep state. Tests on the
same worker will share those service instances.

The bridge does not isolate databases or other external services.

## Parallel resources

Workers run tests at the same time. Split shared external resources for each
worker. Alternatively, protect them with a concurrency limit.

Greenlight sets `GREENLIGHT_CHANNEL` in every worker process. It is a stable
number from 1 through the worker count, and no two concurrent tests use the same
channel. Use it in normal Laravel configuration to key shared resources:

<!-- php-example {"mode":"display","reason":"Shows one entry from a larger PHP configuration array."} -->
```php
// config/database.php
'database' => env('DB_DATABASE', 'app') . '_test_' . env('GREENLIGHT_CHANNEL', '1'),
```

The same pattern works for cache prefixes, storage paths, queue names, and
similar resources.

The application must create and migrate databases for each channel. Use a loop
in the test bootstrap, a Makefile target, or another project-level setup step.
Channel numbers remain stable after a worker crash. Thus, these schemas can
remain for the complete test run.

If a service cannot be split per channel, mark the classes that use it with
`#[RequiresResource]`. Configure its safe concurrency:

<!-- php-example {"mode":"display","reason":"Uses an ellipsis to omit code that is not relevant to the example."} -->
```php
#[RequiresResource('payments-sandbox')]
final class PaymentGatewayTest { ... }
```

<!-- php-example {"example":"laravel-example-08","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
return GreenlightConfig::create()
    ->resourceLimit('payments-sandbox', 2);
```

The limit controls how many classes that require this resource can run. It does
not choose a service instance, and it does not coordinate another Greenlight
process or CI shard. See [configuration](configuration.md) for the complete
resource rules.

## Doubles and the container

The bridge does not replace container services with doubles. If a test needs a
doubled collaborator, get the double through `Doubles`. Then construct the test
subject directly. Greenlight then controls the double lifecycle and
verification.

## Non-goals

The current bridge does not cover:

* HTTP request and response tests
* Laravel `TestCase` helpers and fakes for queues, mail, and events
* `RefreshDatabase` and transaction rollback isolation
* database creation or migration tools
* bootstrap file auto-discovery
* Dusk browser tests

# PSR-15 HTTP applications

The PSR-15 harness sends PSR-7 server requests directly to an application
request handler. It returns PSR-7 responses without a web server or emitter.

The harness does not read PHP globals. It does not use framework-specific
request helpers. Thus, each request is a normal immutable object in the test.

Greenlight has no runtime dependencies. Your test project must provide the
PSR interfaces and one PSR-7 implementation.

For example, install the interfaces and Laminas Diactoros:

```console
composer require --dev psr/http-message psr/http-server-handler laminas/laminas-diactoros
```

## Setup

Register `Psr15Plugin` with a request-handler factory:

```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Psr15\Psr15Plugin;
use Psr\Http\Server\RequestHandlerInterface;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/tests'])
    ->plugins(new Psr15Plugin(
        static fn(): RequestHandlerInterface => require __DIR__ . '/bootstrap/http.php',
    ));
```

The factory runs when a test sends its first request. The same test uses the
same handler for all its requests.

By default, the service scope closes after each test. The next test gets a new
handler from the factory.

### Mezzio

A Mezzio `Application` implements `RequestHandlerInterface`. Return the
application from the factory:

```php
use Mezzio\Application;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;

static function (): RequestHandlerInterface {
    /** @var ContainerInterface $container */
    $container = require __DIR__ . '/config/container.php';
    $application = $container->get(Application::class);

    if (!$application instanceof RequestHandlerInterface) {
        throw new \RuntimeException('The application service is not a PSR-15 request handler.');
    }

    return $application;
}
```

This container access constructs the Mezzio handler only. `Psr15Plugin` does
not resolve container services for test constructors.

Use a separate container bridge when tests also need application services.
This dependency direction keeps HTTP execution separate from service
resolution.

## Send requests

Declare `HttpHarness` in the test constructor. Build requests with the PSR-7
implementation from the application:

```php
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Psr15\HttpHarness;
use Laminas\Diactoros\ServerRequest;

final readonly class StatusTest
{
    public function __construct(private HttpHarness $http) {}

    #[Test]
    public function statusIsReady(): void
    {
        $request = new ServerRequest(
            serverParams: [],
            uploadedFiles: [],
            uri: 'https://example.test/status',
            method: 'GET',
        );

        $response = $this->http->send($request);

        Expect::that($response->getStatusCode())->toBe(200);
        Expect::that((string) $response->getBody())->toBe('{"status":"ready"}');
    }
}
```

`send()` gives the exact request object to the handler. It returns the exact
response object from the handler.

The harness does not change headers, cookies, uploads, attributes, or the
request body. Configure these values on the PSR-7 request.

## Handler lifecycle

The default per-test scope isolates in-memory application state. Use a factory
when each test needs a new handler.

A concrete handler keeps its identity when Greenlight creates a new harness.
Use this option only when the handler has no test state.

Use a release callback when the handler owns resources:

```php
new Psr15Plugin(
    handler: static fn(): RequestHandlerInterface => createApplication(),
    release: static function (RequestHandlerInterface $handler): void {
        if ($handler instanceof ApplicationLifecycle) {
            $handler->close();
        }
    },
)
```

Greenlight calls the callback only when a request constructed the handler. It
calls the callback when the configured service scope closes.

The callback runs once. A callback failure becomes a test error and keeps the
original throwable as its cause.

### Worker-lifetime handlers

Use `Scope::PerRun` only when the handler safely keeps state for the worker
lifetime:

```php
use Greenlight\Harness\Scope;

new Psr15Plugin(
    handler: static fn(): RequestHandlerInterface => createApplication(),
    scope: Scope::PerRun,
)
```

The run scope closes after the worker finishes its assignments. Isolate each
external resource with `GREENLIGHT_CHANNEL` in both service scopes.

## Diagnostics

The PSR-15 harness reports these failures at its public seam:

* The handler factory throws.
* The factory returns a value that is not a PSR-15 request handler.
* The handler throws for a request.
* The release callback throws.
* A caller uses a closed harness.

A request failure names the handler, HTTP method, and URI path. It excludes the
query to reduce accidental disclosure of sensitive values.

Each wrapped failure keeps the original throwable as its cause. Greenlight
then reports the application stack with the harness diagnostic.

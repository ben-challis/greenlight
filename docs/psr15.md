# PSR-15 HTTP applications

The PSR-15 harness sends a
[PSR-7 server request](https://www.php-fig.org/psr/psr-7/) directly to a
[PSR-15 request handler](https://www.php-fig.org/psr/psr-15/). It returns the
handler's PSR-7 response without a web server or emitter.

The harness does not read PHP globals or use framework request helpers. Build
each request as a PSR-7 server request.

This guide uses [Nyholm PSR-7](https://github.com/Nyholm/psr7). Install it with
the required interfaces:

```console
composer require --dev psr/http-message psr/http-server-handler nyholm/psr7
```

## Setup

Register `Psr15Plugin` with a request-handler factory:

<!-- php-example {"example":"psr15-example-01","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Config\GreenlightConfig;
use Greenlight\Psr15\Psr15Plugin;
use Psr\Http\Server\RequestHandlerInterface;

return GreenlightConfig::create()
    ->paths([__DIR__ . '/tests'])
    ->plugins(static fn(): Psr15Plugin => new Psr15Plugin(
        static fn(): RequestHandlerInterface => require __DIR__ . '/bootstrap/http.php',
    ));
```

The factory runs when a test sends its first request. The same test uses the
same handler for all its requests.

By default, the service scope closes after each test. The next test gets a new
handler from the factory.

### Mezzio

[Mezzio](https://docs.mezzio.dev/mezzio/) is a framework for PSR-15 middleware
applications. A Mezzio `Application` implements `RequestHandlerInterface`.
Create `bootstrap/http.php` for the factory in the setup example. Load the
container, middleware pipeline, and routes, then return the application:

<!-- php-example {"example":"psr15-example-02","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Mezzio\Application;
use Mezzio\MiddlewareFactory;
use Psr\Container\ContainerInterface;

/** @var ContainerInterface $container */
$container = require __DIR__ . '/../config/container.php';
$application = $container->get(Application::class);
$middlewareFactory = $container->get(MiddlewareFactory::class);

if (!$application instanceof Application) {
    throw new \RuntimeException('The application service is not a Mezzio application.');
}

if (!$middlewareFactory instanceof MiddlewareFactory) {
    throw new \RuntimeException('The middleware factory service has an incorrect type.');
}

(require __DIR__ . '/../config/pipeline.php')($application, $middlewareFactory, $container);
(require __DIR__ . '/../config/routes.php')($application, $middlewareFactory, $container);

return $application;
```

The Mezzio skeleton keeps pipeline and route setup outside the container.
Without these steps, the handler cannot dispatch application routes. See the
[Mezzio quick start](https://docs.mezzio.dev/mezzio/v3/getting-started/quick-start/).

This bootstrap file returns the handler that the plugin factory expects.
`Psr15Plugin` does not supply container services to test constructors.

If tests need application services, also register the
[PSR-11 bridge](psr11.md).

## Send requests

Declare `HttpHarness` in the test constructor. Build requests with the
application's PSR-7 implementation:

<!-- php-example {"example":"psr15-example-03","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Psr15\HttpHarness;
use Nyholm\Psr7\Factory\Psr17Factory;

final readonly class StatusTest
{
    public function __construct(private HttpHarness $http) {}

    #[Test]
    public function statusIsReady(): void
    {
        $request = new Psr17Factory()->createServerRequest(
            'GET',
            'https://example.test/status',
        );

        $response = $this->http->send($request);

        Expect::that($response->getStatusCode())->toBe(200);
        Expect::that((string) $response->getBody())->toBe('{"status":"ready"}');
    }
}
```

`send()` passes the request object to the handler and returns its response.

Build the PSR-7 request with the required headers, cookies, uploads,
attributes, and body.

## Handler lifetime

The default scope gives each test a new harness. Pass a factory to give each
test a new handler.

If the handler keeps no test state, pass it directly. Each harness then uses
the same handler object.

If you also configure a release callback, Greenlight passes this shared
handler to the callback when each active harness scope closes. A later scope
can use the same handler again. Use a factory if the release callback makes
the handler unusable.

Use a release callback when the handler owns resources:

<!-- php-example {"example":"psr15-example-04","file":"snippet.php","mode":"statements","tools":["rector"]} -->
```php
new Psr15Plugin(
    handler: static fn(): RequestHandlerInterface => createApplication(),
    release: static function (RequestHandlerInterface $handler): void {
        if ($handler instanceof ApplicationLifecycle) {
            $handler->close();
        }
    },
);
```

Greenlight calls the callback only if the harness has an active handler. It
calls the callback when the configured service scope closes.

The callback runs once for each active handler. If the callback throws,
Greenlight reports a test error and keeps the throwable as its cause.

### Worker lifetime

If handler state can remain between tests, use `Scope::PerWorker`:

<!-- php-example {"example":"psr15-example-05","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Greenlight\Harness\Scope;

new Psr15Plugin(
    handler: static fn(): RequestHandlerInterface => createApplication(),
    scope: Scope::PerWorker,
);
```

The worker scope closes after the worker finishes its assignments. Isolate each
external resource with `GREENLIGHT_CHANNEL` in both service scopes.

## Diagnostics

The PSR-15 harness reports:

* The handler factory throws.
* The factory returns a value that is not a PSR-15 request handler.
* The handler throws for a request.
* The release callback throws.
* A caller uses a closed harness.

A request failure identifies the handler, HTTP method, and URI path. The
diagnostic omits the query because it can contain sensitive values.

Each harness error keeps the original throwable as its cause. Greenlight also
reports the application stack trace.

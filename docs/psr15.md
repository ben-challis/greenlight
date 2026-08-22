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
    ->plugins(new Psr15Plugin(
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
Return the application from the factory:

<!-- php-example {"example":"psr15-example-02","file":"snippet.php","mode":"file","tools":["rector"]} -->
```php
use Mezzio\Application;
use Psr\Container\ContainerInterface;
use Psr\Http\Server\RequestHandlerInterface;

return static function (): RequestHandlerInterface {
    /** @var ContainerInterface $container */
    $container = require __DIR__ . '/config/container.php';
    $application = $container->get(Application::class);

    if (!$application instanceof RequestHandlerInterface) {
        throw new \RuntimeException('The application service is not a PSR-15 request handler.');
    }

    return $application;
};
```

This factory uses the PSR-11 container to get the Mezzio request handler.
`Psr15Plugin` does not supply container services to test constructors.

If tests need application services, also register the
[PSR-11 bridge](psr.md).

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

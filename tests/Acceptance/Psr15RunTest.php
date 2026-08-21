<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class Psr15RunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function sendsPsr7RequestsThroughAnIsolatedMezzioApplication(): void
    {
        $project = $this->writeProject();
        $result = GreenlightCli::run($project->directory, ['run', '--reporter=plain', '--workers=1']);

        $output = $result->output();
        Expect::that($result->exitCode)
            ->because($output === '' ? 'the PSR-15 acceptance run has no output' : $output)
            ->toBe(0);
        Expect::that($result->output())->toContain('2 tests, 2 passed');
    }

    private function writeProject(): AcceptanceProject
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'psr15');

        $project->writeFile('application.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Psr15Probe;

            use Laminas\Diactoros\Response\JsonResponse;
            use Laminas\HttpHandlerRunner\RequestHandlerRunnerInterface;
            use Laminas\Stratigility\MiddlewarePipe;
            use Mezzio\Application;
            use Mezzio\MiddlewareContainer;
            use Mezzio\MiddlewareFactory;
            use Mezzio\Router\FastRouteRouter;
            use Mezzio\Router\Middleware\DispatchMiddleware;
            use Mezzio\Router\Middleware\RouteMiddleware;
            use Mezzio\Router\RouteCollector;
            use Psr\Container\ContainerInterface;
            use Psr\Container\NotFoundExceptionInterface;
            use Psr\Http\Message\ResponseInterface;
            use Psr\Http\Message\ServerRequestInterface;
            use Psr\Http\Server\RequestHandlerInterface;

            final class EmptyContainer implements ContainerInterface
            {
                public function get(string $id): never
                {
                    throw new class ($id) extends \RuntimeException implements NotFoundExceptionInterface {
                        public function __construct(string $id)
                        {
                            parent::__construct(\sprintf('Service "%s" does not exist.', $id));
                        }
                    };
                }

                public function has(string $id): bool
                {
                    return false;
                }
            }

            final class HandlerOnlyRunner implements RequestHandlerRunnerInterface
            {
                public function run(): never
                {
                    throw new \LogicException('This test sends requests through RequestHandlerInterface.');
                }
            }

            final class MezzioHandlerFactory
            {
                public static function create(): RequestHandlerInterface
                {
                    $router = new FastRouteRouter();
                    $factory = new MiddlewareFactory(new MiddlewareContainer(new EmptyContainer()));
                    $application = new Application(
                        $factory,
                        new MiddlewarePipe(),
                        new RouteCollector($router),
                        new HandlerOnlyRunner(),
                    );
                    $state = new \stdClass();
                    $state->visits = 0;
                    $application->get('/hello/{name}', new readonly class ($state) implements RequestHandlerInterface {
                        public function __construct(private \stdClass $state) {}

                        public function handle(ServerRequestInterface $request): ResponseInterface
                        {
                            ++$this->state->visits;

                            return new JsonResponse([
                                'hello' => $request->getAttribute('name'),
                                'visit' => $this->state->visits,
                            ]);
                        }
                    });
                    $application->pipe(new RouteMiddleware($router));
                    $application->pipe(new DispatchMiddleware());

                    return $application;
                }
            }

            PHP);

        $project->writeFile('tests/MezzioTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace Psr15Probe;

            use Greenlight\Attribute\Test;
            use Greenlight\Expect\Expect;
            use Greenlight\Psr15\HttpHarness;
            use Laminas\Diactoros\ServerRequest;
            use Psr\Http\Message\ResponseInterface;

            final readonly class MezzioTest
            {
                public function __construct(private HttpHarness $http) {}

                #[Test]
                public function sendsMoreThanOneRequestToTheActiveApplication(): void
                {
                    $first = $this->http->send(new ServerRequest([], [], 'https://example.test/hello/Ada', 'GET'));
                    $second = $this->http->send(new ServerRequest([], [], 'https://example.test/hello/Grace', 'GET'));

                    $this->expectResponse($first, '{"hello":"Ada","visit":1}');
                    $this->expectResponse($second, '{"hello":"Grace","visit":2}');
                }

                #[Test]
                public function startsTheNextTestWithAFreshApplication(): void
                {
                    $response = $this->http->send(
                        new ServerRequest([], [], 'https://example.test/hello/Linus', 'GET'),
                    );

                    $this->expectResponse($response, '{"hello":"Linus","visit":1}');
                }

                private function expectResponse(ResponseInterface $response, string $body): void
                {
                    Expect::that($response->getStatusCode())->toBe(200);
                    Expect::that($response->getHeaderLine('Content-Type'))->toBe('application/json');
                    Expect::that((string) $response->getBody())->toBe($body);
                }
            }

            PHP);

        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Psr15\Psr15Plugin;
            use Psr15Probe\MezzioHandlerFactory;

            require_once __DIR__ . '/application.php';
            require_once __DIR__ . '/tests/MezzioTest.php';

            return GreenlightConfig::create()
                ->paths([__DIR__ . '/tests'])
                ->plugins(new Psr15Plugin(MezzioHandlerFactory::create(...)));

            PHP);

        return $project;
    }
}

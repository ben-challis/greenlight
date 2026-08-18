<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Psr15;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Psr15\HttpHarness;
use Greenlight\Psr15\Psr15Error;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class HttpHarnessTest
{
    #[Test]
    public function sendsTheExactRequestAndReturnsTheHandlerResponse(): void
    {
        $request = new ServerRequest([], [], 'https://example.test/status', 'GET');
        $response = new TextResponse('ready');
        $handler = new class ($response) implements RequestHandlerInterface {
            public ?ServerRequestInterface $received = null;

            public function __construct(private readonly ResponseInterface $response) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                $this->received = $request;

                return $this->response;
            }
        };

        $result = new HttpHarness($handler)->send($request);

        Expect::that($handler->received)->toBe($request);
        Expect::that($result)->toBe($response);
    }

    #[Test]
    public function constructsOneHandlerLazilyForAllRequestsInTheScope(): void
    {
        $created = 0;
        $counter = new RequestCounter();
        $harness = new HttpHarness(static function () use (&$created, $counter): RequestHandlerInterface {
            ++$created;

            return new readonly class ($counter) implements RequestHandlerInterface {
                public function __construct(private RequestCounter $counter) {}

                #[\Override]
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    ++$this->counter->handled;

                    return new TextResponse('ready');
                }
            };
        });
        $request = new ServerRequest([], [], '/status', 'GET');

        $harness->send($request);
        $harness->send($request);

        Expect::that($created)->toBe(1);
        Expect::that($counter->handled)->toBe(2);
    }

    #[Test]
    public function reportsAHandlerFactoryFailureWithItsCause(): void
    {
        $cause = new \RuntimeException('Application construction failed.');
        $harness = new HttpHarness(static function () use ($cause): never {
            throw $cause;
        });

        $error = $this->errorFrom(static fn(): ResponseInterface => $harness->send(
            new ServerRequest([], [], '/status', 'GET'),
        ));

        Expect::that($error->getMessage())->toBe('The PSR-15 handler factory failed.');
        Expect::that($error->getPrevious())->toBe($cause);
    }

    #[Test]
    public function reportsAFactoryResultThatIsNotAHandler(): void
    {
        $harness = new HttpHarness($this->invalidHandlerFactory());

        Expect::that(static fn(): ResponseInterface => $harness->send(
            new ServerRequest([], [], '/status', 'GET'),
        ))->toThrow(
            Psr15Error::class,
            message: 'The PSR-15 handler factory returned "stdClass". '
                . 'It MUST return an instance of "Psr\\Http\\Server\\RequestHandlerInterface".',
        );
    }

    #[Test]
    public function reportsTheRequestAndHandlerWhenHandlingFails(): void
    {
        $cause = new \RuntimeException('Database failed.');
        $handler = new readonly class ($cause) implements RequestHandlerInterface {
            public function __construct(private \Throwable $cause) {}

            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                throw $this->cause;
            }
        };
        $harness = new HttpHarness($handler);

        $error = $this->errorFrom(static fn(): ResponseInterface => $harness->send(
            new ServerRequest([], [], 'https://example.test/orders/42?token=private', 'PATCH'),
        ));

        Expect::that($error->getMessage())->toMatch('/failed for request "PATCH \/orders\/42"\.$/');
        Expect::that($error->getMessage())->not()->toContain('token');
        Expect::that($error->getPrevious())->toBe($cause);
    }

    #[Test]
    public function releasesTheActiveHandlerOnlyOnce(): void
    {
        $handler = $this->textHandler();
        $released = [];
        $harness = new HttpHarness(
            $handler,
            static function (RequestHandlerInterface $active) use (&$released): void {
                $released[] = $active;
            },
        );
        $harness->send(new ServerRequest([], [], '/status', 'GET'));

        $harness->dispose();
        $harness->dispose();

        Expect::that($released)->toBe([$handler]);
    }

    #[Test]
    public function anUnusedHarnessDoesNotConstructOrReleaseAHandler(): void
    {
        $created = false;
        $released = false;
        $harness = new HttpHarness(
            static function () use (&$created): RequestHandlerInterface {
                $created = true;

                return new class implements RequestHandlerInterface {
                    #[\Override]
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return new TextResponse('ready');
                    }
                };
            },
            static function (RequestHandlerInterface $handler) use (&$released): void {
                $released = true;
            },
        );

        $harness->dispose();

        Expect::that($created)->toBeFalse();
        Expect::that($released)->toBeFalse();
    }

    #[Test]
    public function aReleaseFailureClosesTheHarnessAndKeepsTheCause(): void
    {
        $cause = new \RuntimeException('Application release failed.');
        $harness = new HttpHarness(
            $this->textHandler(),
            static function (RequestHandlerInterface $handler) use ($cause): never {
                throw $cause;
            },
        );
        $request = new ServerRequest([], [], '/status', 'GET');
        $harness->send($request);

        $error = $this->errorFrom($harness->dispose(...));

        Expect::that($error->getMessage())->toContain('The release callback failed for PSR-15 handler');
        Expect::that($error->getPrevious())->toBe($cause);
        Expect::that(static fn(): ResponseInterface => $harness->send($request))->toThrow(
            Psr15Error::class,
            message: 'The PSR-15 HTTP harness is closed. Create a new harness for the next request.',
        );
    }

    private function textHandler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new TextResponse('ready');
            }
        };
    }

    /** @return \Closure(): mixed */
    private function invalidHandlerFactory(): \Closure
    {
        return static fn(): mixed => new \stdClass();
    }

    /**
     * @param \Closure(): mixed $operation
     */
    private function errorFrom(\Closure $operation): Psr15Error
    {
        try {
            $operation();
        } catch (Psr15Error $error) {
            return $error;
        }

        Fail::because('The PSR-15 operation did not fail.');
    }
}

/** @internal */
final class RequestCounter
{
    public int $handled = 0;
}

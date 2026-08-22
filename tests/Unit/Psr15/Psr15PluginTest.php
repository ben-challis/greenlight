<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Psr15;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Psr15\HttpHarness;
use Greenlight\Psr15\Psr15Error;
use Greenlight\Psr15\Psr15Plugin;
use Laminas\Diactoros\Response\TextResponse;
use Laminas\Diactoros\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

final class Psr15PluginTest
{
    #[Test]
    public function suppliesAPerTestHttpHarnessByDefault(): void
    {
        $definition = new Psr15Plugin($this->handler())->services()[0];

        Expect::that($definition->type)->toBe(HttpHarness::class);
        Expect::that($definition->scope)->toBe(Scope::PerTest);
    }

    #[Test]
    public function aHandlerFactoryGivesEachTestAnIsolatedHandler(): void
    {
        $created = 0;
        $plugin = new Psr15Plugin(static function () use (&$created): RequestHandlerInterface {
            ++$created;

            return new class implements RequestHandlerInterface {
                #[\Override]
                public function handle(ServerRequestInterface $request): ResponseInterface
                {
                    return new TextResponse('ready');
                }
            };
        });
        $scopes = $this->scopes($plugin);

        $scopes->openTest();
        $scopes->resolve(HttpHarness::class, self::class)->send(new ServerRequest([], [], '/first', 'GET'));
        $scopes->closeTest();
        $scopes->openTest();
        $scopes->resolve(HttpHarness::class, self::class)->send(new ServerRequest([], [], '/second', 'GET'));
        $scopes->closeTest();

        Expect::that($created)->toBe(2);
    }

    #[Test]
    public function aPerWorkerScopeReusesOneHarnessAndHandler(): void
    {
        $created = 0;
        $plugin = new Psr15Plugin(
            static function () use (&$created): RequestHandlerInterface {
                ++$created;

                return new class implements RequestHandlerInterface {
                    #[\Override]
                    public function handle(ServerRequestInterface $request): ResponseInterface
                    {
                        return new TextResponse('ready');
                    }
                };
            },
            Scope::PerWorker,
        );
        $scopes = $this->scopes($plugin);

        $scopes->openTest();
        $first = $scopes->resolve(HttpHarness::class, self::class);
        $first->send(new ServerRequest([], [], '/first', 'GET'));
        $scopes->closeTest();
        $scopes->openTest();
        $second = $scopes->resolve(HttpHarness::class, self::class);
        $second->send(new ServerRequest([], [], '/second', 'GET'));
        $scopes->closeTest();

        Expect::that($second)->toBe($first);
        Expect::that($created)->toBe(1);
        Expect::that($scopes->closeWorker())->toBe([]);
    }

    #[Test]
    public function scopeClosureReleasesTheHandler(): void
    {
        $handler = $this->handler();
        $released = null;
        $plugin = new Psr15Plugin(
            $handler,
            release: static function (RequestHandlerInterface $active) use (&$released): void {
                $released = $active;
            },
        );
        $scopes = $this->scopes($plugin);
        $scopes->openTest();
        $scopes->resolve(HttpHarness::class, self::class)->send(new ServerRequest([], [], '/status', 'GET'));

        Expect::that($scopes->closeTest())->toBe([]);
        Expect::that($released)->toBe($handler);
    }

    #[Test]
    public function scopeClosureReportsAReleaseFailure(): void
    {
        $cause = new \RuntimeException('Release failed.');
        $plugin = new Psr15Plugin(
            $this->handler(),
            release: static function (RequestHandlerInterface $handler) use ($cause): never {
                throw $cause;
            },
        );
        $scopes = $this->scopes($plugin);
        $scopes->openTest();
        $scopes->resolve(HttpHarness::class, self::class)->send(new ServerRequest([], [], '/status', 'GET'));

        $failures = $scopes->closeTest();

        Expect::that($failures)->toHaveCount(1);
        Expect::that($failures[0])->toBeInstanceOf(Psr15Error::class);
        Expect::that($failures[0]->getPrevious())->toBe($cause);
    }

    private function handler(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            #[\Override]
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new TextResponse('ready');
            }
        };
    }

    private function scopes(Psr15Plugin $plugin): HarnessScopes
    {
        return new HarnessScopes(new HarnessRegistry($plugin->services()));
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Psr11;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceResolution;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Plugin\TestContext;
use Greenlight\Psr11\Psr11BridgeError;
use Greenlight\Psr11\Psr11Plugin;
use Greenlight\Psr11\Service;
use Greenlight\Result\TestResult;
use Greenlight\Tests\Support\PluginLifecycle;
use Greenlight\Tests\Support\Psr11\ArrayContainer;
use Greenlight\Tests\Support\Psr11\Greeter;
use Greenlight\Tests\Support\ServiceResolverProbe;
use Psr\Container\ContainerInterface;

final readonly class Psr11PluginTest
{
    #[Test]
    public function resolvesContainerServicesByType(): void
    {
        $greeter = new Greeter();
        $plugin = $this->plugin([Greeter::class => $greeter]);

        Expect::that($plugin->resolve(Greeter::class, [])->value())->toBe($greeter);
    }

    #[Test]
    public function resolvesAServiceByExplicitId(): void
    {
        $greeter = new Greeter();
        $plugin = $this->plugin(['application.greeter' => $greeter]);
        $resolved = $plugin->resolve(Greeter::class, [new Service('application.greeter')])->value();

        Expect::that($resolved)->toBe($greeter);
    }

    #[Test]
    public function anUnknownTypeReturnsNull(): void
    {
        Expect::that($this->plugin([])->resolve(Greeter::class, [])->value())->toBeNull();
    }

    #[Test]
    public function anUnknownTypeFallsThroughToTheNextResolver(): void
    {
        $answer = new Greeter();
        $later = new ServiceResolverProbe(ServiceResolution::resolved($answer));
        $scopes = new HarnessScopes(new HarnessRegistry(), [$this->plugin([]), $later]);

        Expect::that($scopes->resolve(Greeter::class, 'test'))
            ->because('an unknown PSR-11 type MUST fall through to the next resolver')
            ->toBe($answer);
        Expect::that($later->calls)->toBe(1);
    }

    #[Test]
    public function anUnknownExplicitServiceStopsTheResolverChain(): void
    {
        $later = new ServiceResolverProbe(ServiceResolution::resolved(new Greeter()));
        $scopes = new HarnessScopes(new HarnessRegistry(), [$this->plugin([]), $later]);

        Expect::that(static fn(): object => $scopes->resolve(
            Greeter::class,
            'test',
            [new Service('missing')],
        ))
            ->because('an explicit PSR-11 service failure MUST stop the resolver chain')
            ->toThrow(ServiceResolutionFailed::class, matching: '/no service "missing"/');
        Expect::that($later->calls)->toBe(0);
    }

    #[Test]
    public function anUnknownExplicitIdFailsLoudly(): void
    {
        $plugin = $this->plugin([]);

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, [new Service('missing')])->value();
        })->toThrow(
            Psr11BridgeError::class,
            matching: '/no service "missing".*Check the service ID/s',
        );
    }

    #[Test]
    public function aServiceOfTheWrongTypeFailsLoudly(): void
    {
        $plugin = $this->plugin(['application' => new \stdClass()]);

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, [new Service('application')])->value();
        })->toThrow(
            Psr11BridgeError::class,
            matching: '/service "application" has type "stdClass".*requires type/s',
        );
    }

    #[Test]
    public function pluginConstructionIsLazy(): void
    {
        $created = false;
        $plugin = new Psr11Plugin(static function () use (&$created): ContainerInterface {
            $created = true;

            return new ArrayContainer([]);
        });

        Expect::that($plugin)->toBeInstanceOf(Psr11Plugin::class);
        Expect::that($created)
            ->because('plugin construction MUST NOT create the container')
            ->toBeFalse();
    }

    #[Test]
    public function theHarnessFactoryReturnsTheActiveContainer(): void
    {
        $container = new ArrayContainer([]);
        $plugin = new Psr11Plugin(static fn(): ContainerInterface => $container);

        Expect::that($this->containerFrom($plugin))->toBe($container);
        Expect::that($this->containerFrom($plugin))->toBe($container);
    }

    #[Test]
    public function theDefaultLifecycleCreatesAContainerForEachTest(): void
    {
        $created = 0;
        $plugin = new Psr11Plugin(static function () use (&$created): ContainerInterface {
            ++$created;

            return new ArrayContainer([Greeter::class => new Greeter()]);
        });
        $first = $plugin->resolve(Greeter::class, [])->value();

        $plugin->afterTest($this->context(), $this->result());

        Expect::that($plugin->resolve(Greeter::class, [])->value())->not()->toBe($first);
        Expect::that($created)->toBe(2);
    }

    #[Test]
    public function aSharedLifecycleKeepsTheWorkerContainer(): void
    {
        $created = 0;
        $plugin = new Psr11Plugin(
            static function () use (&$created): ContainerInterface {
                ++$created;

                return new ArrayContainer([Greeter::class => new Greeter()]);
            },
            refreshBetweenTests: false,
        );
        $first = $plugin->resolve(Greeter::class, [])->value();

        $plugin->afterTest($this->context(), $this->result());

        Expect::that($plugin->resolve(Greeter::class, [])->value())->toBe($first);
        Expect::that($created)->toBe(1);
    }

    #[Test]
    public function theResetCallbackReceivesTheActiveContainer(): void
    {
        $container = new ArrayContainer([]);
        $resetContainer = null;
        $plugin = new Psr11Plugin(
            static fn(): ContainerInterface => $container,
            reset: static function (ContainerInterface $active) use (&$resetContainer): void {
                $resetContainer = $active;
            },
        );
        $this->containerFrom($plugin);

        $plugin->afterTest($this->context(), $this->result());

        Expect::that($resetContainer)->toBe($container);
    }

    #[Test]
    public function aFailedResetStillDiscardsAPerTestContainer(): void
    {
        $failure = new \RuntimeException('Reset failed.');
        $created = 0;
        $plugin = new Psr11Plugin(
            static function () use (&$created): ContainerInterface {
                ++$created;

                return new ArrayContainer([]);
            },
            reset: static function (ContainerInterface $container) use ($failure): never {
                throw $failure;
            },
        );
        $this->containerFrom($plugin);

        $error = null;

        try {
            $plugin->afterTest($this->context(), $this->result());
        } catch (Psr11BridgeError $caught) {
            $error = $caught;
        }

        Expect::that($error)
            ->because('The reset callback MUST fail.')
            ->toBeInstanceOf(Psr11BridgeError::class);

        Expect::that($error->getPrevious())->toBe($failure);
        $this->containerFrom($plugin);
        Expect::that($created)->toBe(2);
    }

    #[Test]
    public function aFailedResetDiscardsASharedContainer(): void
    {
        $failure = new \RuntimeException('Reset failed.');
        $created = 0;
        $plugin = new Psr11Plugin(
            static function () use (&$created): ContainerInterface {
                ++$created;

                return new ArrayContainer([Greeter::class => new Greeter()]);
            },
            refreshBetweenTests: false,
            reset: static function (ContainerInterface $container) use ($failure): never {
                throw $failure;
            },
        );
        $plugin->resolve(Greeter::class, [])->value();

        try {
            $plugin->afterTest($this->context(), $this->result());
        } catch (Psr11BridgeError $error) {
            Expect::that($error->getPrevious())->toBe($failure);
        }

        $plugin->resolve(Greeter::class, [])->value();
        Expect::that($created)->toBe(2);
    }

    #[Test]
    public function aContainerFactoryFailureKeepsTheCause(): void
    {
        $failure = new \RuntimeException('Container construction failed.');
        $plugin = new Psr11Plugin(static function () use ($failure): never {
            throw $failure;
        });

        $error = null;

        try {
            $plugin->resolve(Greeter::class, [])->value();
        } catch (Psr11BridgeError $caught) {
            $error = $caught;
        }

        Expect::that($error)
            ->because('The container factory MUST fail.')
            ->toBeInstanceOf(Psr11BridgeError::class);

        Expect::that($error->getPrevious())->toBe($failure);
    }

    #[Test]
    public function aFactoryMustReturnAPsr11Container(): void
    {
        $plugin = new Psr11Plugin($this->invalidContainerFactory()); // @phpstan-ignore argument.type (This test deliberately supplies an invalid factory result.)

        Expect::that(static fn(): ?object => $plugin->resolve(Greeter::class, [])->value())->toThrow(
            Psr11BridgeError::class,
            matching: '/returned "stdClass".*ContainerInterface/',
        );
    }

    #[Test]
    public function aContainerCheckFailureKeepsTheCause(): void
    {
        $failure = new \RuntimeException('The container cannot check services.');
        $container = new readonly class ($failure) implements ContainerInterface {
            public function __construct(private \Throwable $failure) {}

            #[\Override]
            public function get(string $id): mixed
            {
                return new \stdClass();
            }

            #[\Override]
            public function has(string $id): bool
            {
                throw $this->failure;
            }
        };
        $plugin = new Psr11Plugin(static fn(): ContainerInterface => $container);

        $this->expectCause(
            static fn(): ?object => $plugin->resolve(Greeter::class, [])->value(),
            $failure,
            '/failed when it checked service/',
        );
    }

    #[Test]
    public function aContainerReadFailureKeepsTheCause(): void
    {
        $failure = new \RuntimeException('The container cannot read services.');
        $container = new readonly class ($failure) implements ContainerInterface {
            public function __construct(private \Throwable $failure) {}

            #[\Override]
            public function get(string $id): mixed
            {
                throw $this->failure;
            }

            #[\Override]
            public function has(string $id): bool
            {
                return true;
            }
        };
        $plugin = new Psr11Plugin(static fn(): ContainerInterface => $container);

        $this->expectCause(
            static fn(): ?object => $plugin->resolve(Greeter::class, [])->value(),
            $failure,
            '/failed when it read service/',
        );
    }

    #[Test]
    public function theContainerHarnessServiceUsesTheConfiguredLifecycle(): void
    {
        $perTest = $this->plugin([])->services()[0];
        $perWorker = new Psr11Plugin(
            static fn(): ContainerInterface => new ArrayContainer([]),
            refreshBetweenTests: false,
        );

        Expect::that($perTest->type)->toBe(ContainerInterface::class);
        Expect::that($perTest->scope)->toBe(Scope::PerTest);
        Expect::that($perWorker->services()[0]->scope)->toBe(Scope::PerWorker);
    }

    #[Test]
    public function afterTestWithoutAnActiveContainerIsANoOp(): void
    {
        $created = false;
        $plugin = new Psr11Plugin(static function () use (&$created): ContainerInterface {
            $created = true;

            return new ArrayContainer([]);
        });
        $result = $this->result();

        Expect::that($plugin->afterTest($this->context(), $result))->toBe($result);
        Expect::that($created)->toBeFalse();
    }

    /**
     * @param array<string, mixed> $services
     */
    private function plugin(array $services): Psr11Plugin
    {
        return new Psr11Plugin(static fn(): ContainerInterface => new ArrayContainer($services));
    }

    private function containerFrom(Psr11Plugin $plugin): ContainerInterface
    {
        $container = ($plugin->services()[0]->factory)();

        Expect::that($container)
            ->because('The PSR-11 harness factory MUST return ContainerInterface.')
            ->toBeInstanceOf(ContainerInterface::class);

        return $container;
    }

    /** @return \Closure(): \stdClass */
    private function invalidContainerFactory(): \Closure
    {
        return static fn(): object => new \stdClass();
    }

    /**
     * @param \Closure(): ?object $operation
     */
    private function expectCause(\Closure $operation, \Throwable $cause, string $pattern): void
    {
        try {
            $operation();
        } catch (Psr11BridgeError $error) {
            Expect::that($error->getMessage())->toMatch($pattern);
            Expect::that($error->getPrevious())->toBe($cause);

            return;
        }

        throw new \LogicException('The PSR-11 operation did not fail.');
    }

    private function context(): TestContext
    {
        return PluginLifecycle::context();
    }

    private function result(): TestResult
    {
        return PluginLifecycle::passedResult();
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Psr;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Plugin\TestContext;
use Greenlight\Psr\Psr11BridgeError;
use Greenlight\Psr\Psr11Plugin;
use Greenlight\Psr\Service;
use Greenlight\Tests\Support\Psr\ArrayContainer;
use Greenlight\Tests\Support\Psr\Greeter;
use Psr\Container\ContainerInterface;

final readonly class Psr11PluginTest
{
    #[Test]
    public function resolvesContainerServicesByType(): void
    {
        $greeter = new Greeter();
        $plugin = $this->plugin([Greeter::class => $greeter]);

        Expect::that($plugin->resolve(Greeter::class, []))->toBe($greeter);
    }

    #[Test]
    public function resolvesAServiceByExplicitId(): void
    {
        $greeter = new Greeter();
        $plugin = $this->plugin(['application.greeter' => $greeter]);
        $resolved = $plugin->resolve(Greeter::class, [new Service('application.greeter')]);

        Expect::that($resolved)->toBe($greeter);
    }

    #[Test]
    public function anUnknownTypeReturnsNull(): void
    {
        Expect::that($this->plugin([])->resolve(Greeter::class, []))->toBeNull();
    }

    #[Test]
    public function anUnknownExplicitIdFailsLoudly(): void
    {
        $plugin = $this->plugin([]);

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, [new Service('missing')]);
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
            $plugin->resolve(Greeter::class, [new Service('application')]);
        })->toThrow(
            Psr11BridgeError::class,
            matching: '/service "application" has type "stdClass".*requires type/s',
        );
    }

    #[Test]
    public function containerCreationIsLazy(): void
    {
        $created = false;
        $plugin = new Psr11Plugin(static function () use (&$created): ContainerInterface {
            $created = true;

            return new ArrayContainer([]);
        });

        $plugin->beforeTest($this->context());

        Expect::that($created)->toBeFalse();
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
        $first = $plugin->resolve(Greeter::class, []);

        $plugin->afterTest($this->context(), $this->result());

        Expect::that($plugin->resolve(Greeter::class, []))->not()->toBe($first);
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
        $first = $plugin->resolve(Greeter::class, []);

        $plugin->afterTest($this->context(), $this->result());

        Expect::that($plugin->resolve(Greeter::class, []))->toBe($first);
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

        if (!$error instanceof Psr11BridgeError) {
            Fail::because('The reset callback did not fail.');
        }

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
        $plugin->resolve(Greeter::class, []);

        try {
            $plugin->afterTest($this->context(), $this->result());
        } catch (Psr11BridgeError $error) {
            Expect::that($error->getPrevious())->toBe($failure);
        }

        $plugin->resolve(Greeter::class, []);
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
            $plugin->resolve(Greeter::class, []);
        } catch (Psr11BridgeError $caught) {
            $error = $caught;
        }

        if (!$error instanceof Psr11BridgeError) {
            Fail::because('The container factory did not fail.');
        }

        Expect::that($error->getPrevious())->toBe($failure);
    }

    #[Test]
    public function aFactoryMustReturnAPsr11Container(): void
    {
        $plugin = new Psr11Plugin($this->invalidContainerFactory());

        Expect::that(static fn(): ?object => $plugin->resolve(Greeter::class, []))->toThrow(
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
            static fn(): ?object => $plugin->resolve(Greeter::class, []),
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
            static fn(): ?object => $plugin->resolve(Greeter::class, []),
            $failure,
            '/failed when it read service/',
        );
    }

    #[Test]
    public function theContainerHarnessServiceUsesTheConfiguredLifecycle(): void
    {
        $perTest = $this->plugin([])->services()[0];
        $perRun = new Psr11Plugin(
            static fn(): ContainerInterface => new ArrayContainer([]),
            refreshBetweenTests: false,
        );

        Expect::that($perTest->type)->toBe(ContainerInterface::class);
        Expect::that($perTest->scope)->toBe(Scope::PerTest);
        Expect::that($perRun->services()[0]->scope)->toBe(Scope::PerRun);
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

        if (!$container instanceof ContainerInterface) {
            Fail::because(\sprintf(
                'Expected the PSR-11 harness factory to return ContainerInterface, got %s.',
                \get_debug_type($container),
            ));
        }

        return $container;
    }

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
        return new TestContext(
            new \stdClass(),
            new TestId('Fixture', 'probe'),
            new TestMetadata('Fixture', 'probe'),
            new HarnessScopes(new HarnessRegistry()),
        );
    }

    private function result(): TestResult
    {
        return new TestResult(new TestId('Fixture', 'probe'), Outcome::Passed, 0.0, 0);
    }
}

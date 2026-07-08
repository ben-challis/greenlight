<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Symfony;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Plugin\TestContext;
use Greenlight\Symfony\Service;
use Greenlight\Symfony\SymfonyBridgeError;
use Greenlight\Symfony\SymfonyPlugin;
use Greenlight\Tests\Fixture\Symfony\FixtureKernel;
use Greenlight\Tests\Fixture\Symfony\Greeter;
use Greenlight\Tests\Fixture\Symfony\NamedGreeter;
use Greenlight\Tests\Fixture\Symfony\VisitCounter;
use Symfony\Component\HttpKernel\KernelInterface;

final class SymfonyPluginTest
{
    #[Test]
    public function resolvesContainerServicesByType(): void
    {
        $greeter = $this->plugin()->resolve(Greeter::class, []);

        if (!$greeter instanceof Greeter) {
            throw new \RuntimeException('Expected a Greeter.');
        }

        Expect::that($greeter->greet('Ada'))->toBe('Hello, Ada!');
    }

    #[Test]
    public function resolvesPrivateServicesThroughTheTestContainer(): void
    {
        // VisitCounter is private and unreferenced; only the test container
        // keeps it reachable.
        Expect::that($this->plugin()->resolve(VisitCounter::class, []))
            ->toBeInstanceOf(VisitCounter::class);
    }

    #[Test]
    public function theServiceAttributeResolvesByExplicitId(): void
    {
        $named = $this->plugin()->resolve(NamedGreeter::class, [new Service('fixture.named_greeter')]);

        Expect::that($named)->toBeInstanceOf(NamedGreeter::class);
    }

    #[Test]
    public function aTypeWithoutTheAttributeMissesIdOnlyServices(): void
    {
        Expect::that($this->plugin()->resolve(NamedGreeter::class, []))->toBeNull();
    }

    #[Test]
    public function aTypeTheContainerDoesNotKnowReturnsNull(): void
    {
        Expect::that($this->plugin()->resolve(\ArrayObject::class, []))->toBeNull();
    }

    #[Test]
    public function anUnknownExplicitIdFailsLoudly(): void
    {
        $plugin = $this->plugin();

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, [new Service('fixture.missing')]);
        })->toThrow(SymfonyBridgeError::class, matching: '/no service "fixture\.missing".*Check the id for typos/s');
    }

    #[Test]
    public function anExplicitIdOfTheWrongTypeFailsLoudly(): void
    {
        $plugin = $this->plugin();

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(VisitCounter::class, [new Service('fixture.named_greeter')]);
        })->toThrow(SymfonyBridgeError::class, matching: '/is an instance of .* but the parameter declares/');
    }

    #[Test]
    public function withoutTheTestContainerExplicitIdsHintAtFrameworkTest(): void
    {
        // The prod environment compiles without framework.test, so private
        // services vanish and the error points at the missing test container.
        $plugin = new SymfonyPlugin(FixtureKernel::class, env: 'prod', debug: true);

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(NamedGreeter::class, [new Service('fixture.named_greeter')]);
        })->toThrow(SymfonyBridgeError::class, matching: '/framework\.test/');
    }

    #[Test]
    public function theKernelIsAPerRunHarnessServiceAndBootsOnce(): void
    {
        $plugin = $this->plugin();
        $definitions = $plugin->services();
        $definition = $definitions[0];

        $first = ($definition->factory)();

        if (!$first instanceof KernelInterface) {
            throw new \RuntimeException('Expected a kernel.');
        }

        Expect::that(\count($definitions))->toBe(1)
            ->and($definition->type)->toBe(KernelInterface::class)
            ->and($definition->scope)->toBe(Scope::PerRun)
            ->and($first->getEnvironment())->toBe('test')
            ->and(($definition->factory)())->toBe($first);
    }

    #[Test]
    public function aClosureFactoryBootsTheKernelItProduces(): void
    {
        $plugin = new SymfonyPlugin(static fn(): KernelInterface => new FixtureKernel('test', true));

        Expect::that($plugin->resolve(Greeter::class, []))->toBeInstanceOf(Greeter::class);
    }

    #[Test]
    public function aClassThatIsNotAKernelFailsLoudly(): void
    {
        $plugin = new SymfonyPlugin(\ArrayObject::class);

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->toThrow(SymfonyBridgeError::class, matching: '/does not implement/');
    }

    #[Test]
    public function afterTestResetsStatefulContainerServices(): void
    {
        $plugin = $this->plugin();
        $counter = $plugin->resolve(VisitCounter::class, []);

        if (!$counter instanceof VisitCounter) {
            throw new \RuntimeException('Expected the VisitCounter.');
        }

        $counter->record();
        $counter->record();
        $result = $this->result();
        $returned = $plugin->afterTest($this->context(), $result);

        Expect::that($counter->count())->toBe(0)->and($returned)->toBe($result);
    }

    #[Test]
    public function afterTestWithoutABootedKernelIsANoOp(): void
    {
        $booted = false;
        $plugin = new SymfonyPlugin(static function () use (&$booted): KernelInterface {
            $booted = true;

            return new FixtureKernel('test', true);
        });

        $result = $this->result();
        $returned = $plugin->afterTest($this->context(), $result);

        Expect::that($booted)->toBe(false)->and($returned)->toBe($result);
    }

    private function plugin(): SymfonyPlugin
    {
        return new SymfonyPlugin(FixtureKernel::class, env: 'test', debug: true);
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

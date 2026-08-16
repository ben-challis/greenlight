<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Symfony;

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
use Greenlight\Symfony\Service;
use Greenlight\Symfony\SymfonyBridgeError;
use Greenlight\Symfony\SymfonyPlugin;
use Greenlight\Tests\Fixture\Symfony\BareKernel;
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
            Fail::because(\sprintf(
                'Expected SymfonyPlugin::resolve() to return Greeter, got %s.',
                \get_debug_type($greeter),
            ));
        }

        Expect::that($greeter->greet('Ada'))->because('resolves container services by type')->toBe('Hello, Ada!');
    }

    #[Test]
    public function resolvesPrivateServicesThroughTheTestContainer(): void
    {
        // VisitCounter is private and has no reference. Only the test container
        // keeps it available.
        Expect::that($this->plugin()->resolve(VisitCounter::class, []))->because('resolves private services through the test container')
            ->toBeInstanceOf(VisitCounter::class);
    }

    #[Test]
    public function theServiceAttributeResolvesByExplicitId(): void
    {
        $named = $this->plugin()->resolve(NamedGreeter::class, [new Service('fixture.named_greeter')]);

        Expect::that($named)->because('the service attribute resolves by explicit ID')->toBeInstanceOf(NamedGreeter::class);
    }

    #[Test]
    public function aTypeWithoutTheAttributeMissesIdOnlyServices(): void
    {
        Expect::that($this->plugin()->resolve(NamedGreeter::class, []))->because('a type without the attribute misses ID only services')->toBeNull();
    }

    #[Test]
    public function aTypeTheContainerDoesNotKnowReturnsNull(): void
    {
        Expect::that($this->plugin()->resolve(\ArrayObject::class, []))->because('a type the container does not know returns null')->toBeNull();
    }

    #[Test]
    public function anUnknownExplicitIdFailsLoudly(): void
    {
        $plugin = $this->plugin();

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, [new Service('fixture.missing')]);
        })->because('an unknown explicit ID causes an error')->toThrow(SymfonyBridgeError::class, matching: '/no service "fixture\.missing".*Check the service ID/s');
    }

    #[Test]
    public function anExplicitIdOfTheWrongTypeFailsLoudly(): void
    {
        $plugin = $this->plugin();

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(VisitCounter::class, [new Service('fixture.named_greeter')]);
        })->because('an explicit ID of the wrong type causes an error')->toThrow(SymfonyBridgeError::class, matching: '/has type .* The parameter requires type/');
    }

    #[Test]
    public function aKernelWithoutTheTestContainerFailsAtBoot(): void
    {
        // The prod environment compiles without framework.test. Boot validation
        // rejects it before service resolution uses a less strict path without
        // an error.
        $plugin = new SymfonyPlugin(FixtureKernel::class, env: 'prod', debug: true);

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->because('a kernel without the test container fails at boot')->toThrow(SymfonyBridgeError::class, matching: '/framework\.test/');
    }

    #[Test]
    public function aKernelWithoutServicesResetterFailsAtBoot(): void
    {
        $plugin = new SymfonyPlugin(static fn(): KernelInterface => BareKernel::withTestContainer());

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->because('a kernel without services resetter fails at boot')->toThrow(SymfonyBridgeError::class, matching: '/services_resetter.*resetBetweenTests: false/s');
    }

    #[Test]
    public function waivingResetsAcceptsAKernelWithoutTheResetter(): void
    {
        $plugin = new SymfonyPlugin(
            static fn(): KernelInterface => BareKernel::withTestContainer(),
            resetBetweenTests: false,
        );

        Expect::that($plugin->resolve(Greeter::class, []))->because('waiving resets accepts a kernel without the resetter')->toBeNull();
    }

    #[Test]
    public function waivedResetsLeaveStateInPlace(): void
    {
        $plugin = new SymfonyPlugin(FixtureKernel::class, env: 'test', debug: true, resetBetweenTests: false);
        $counter = $plugin->resolve(VisitCounter::class, []);

        if (!$counter instanceof VisitCounter) {
            Fail::because(\sprintf(
                'Expected SymfonyPlugin::resolve() to return VisitCounter, got %s.',
                \get_debug_type($counter),
            ));
        }

        $counter->record();
        $plugin->afterTest($this->context(), $this->result());

        Expect::that($counter->count())->because('waived resets leave state in place')->toBe(1);
    }

    #[Test]
    public function theKernelIsAPerRunHarnessServiceAndBootsOnce(): void
    {
        $plugin = $this->plugin();
        $definitions = $plugin->services();
        $definition = $definitions[0];

        $first = ($definition->factory)();

        if (!$first instanceof KernelInterface) {
            Fail::because(\sprintf(
                'Expected the Symfony harness factory to return KernelInterface, got %s.',
                \get_debug_type($first),
            ));
        }

        Expect::that($definitions)->because('the kernel is a per run harness service and boots once')->toHaveCount(1);
        Expect::that($definition->type)->toBe(KernelInterface::class);
        Expect::that($definition->scope)->toBe(Scope::PerRun);
        Expect::that($first->getEnvironment())->toBe('test');
        Expect::that(($definition->factory)())->toBe($first);
    }

    #[Test]
    public function aClosureFactoryBootsTheKernelItProduces(): void
    {
        $plugin = new SymfonyPlugin(static fn(): KernelInterface => new FixtureKernel('test', true));

        Expect::that($plugin->resolve(Greeter::class, []))->because('a closure factory boots the kernel it produces')->toBeInstanceOf(Greeter::class);
    }

    #[Test]
    public function aClassThatIsNotAKernelFailsLoudly(): void
    {
        $plugin = new SymfonyPlugin(\ArrayObject::class);

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->because('a class that is not a kernel causes an error')->toThrow(SymfonyBridgeError::class, matching: '/does not implement/');
    }

    #[Test]
    public function afterTestResetsStatefulContainerServices(): void
    {
        $plugin = $this->plugin();
        $counter = $plugin->resolve(VisitCounter::class, []);

        if (!$counter instanceof VisitCounter) {
            Fail::because(\sprintf(
                'Expected SymfonyPlugin::resolve() to return VisitCounter, got %s.',
                \get_debug_type($counter),
            ));
        }

        $counter->record();
        $counter->record();
        $result = $this->result();
        $returned = $plugin->afterTest($this->context(), $result);

        Expect::that($counter->count())->because('after test resets stateful container services')->toBe(0);
        Expect::that($returned)->toBe($result);
    }

    #[Test]
    public function beforeTestPreservesLazyKernelBoot(): void
    {
        $factoryCalled = false;
        $plugin = new SymfonyPlugin(static function () use (&$factoryCalled): KernelInterface {
            $factoryCalled = true;

            Fail::because('SymfonyPlugin::beforeTest() MUST NOT boot the kernel.');
        });

        $plugin->beforeTest($this->context());

        Expect::that($factoryCalled)
            ->because('the kernel remains lazy during beforeTest()')
            ->toBeFalse();
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

        Expect::that($booted)->because('after test without a booted kernel is a no-op')->toBe(false);
        Expect::that($returned)->toBe($result);
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

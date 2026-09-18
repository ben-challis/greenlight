<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Symfony;

use Greenlight\Attribute\Test;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\Service;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Plugin\TestContext;
use Greenlight\Result\TestResult;
use Greenlight\Symfony\SymfonyBridgeError;
use Greenlight\Symfony\SymfonyPlugin;
use Greenlight\Tests\Fixture\Symfony\BareKernel;
use Greenlight\Tests\Fixture\Symfony\FixtureKernel;
use Greenlight\Tests\Fixture\Symfony\Greeter;
use Greenlight\Tests\Fixture\Symfony\NamedGreeter;
use Greenlight\Tests\Fixture\Symfony\VisitCounter;
use Greenlight\Tests\Support\PluginLifecycle;
use Greenlight\Tests\Support\ServiceResolverProbe;
use Symfony\Component\HttpKernel\KernelInterface;

use function Greenlight\expect;

final class SymfonyPluginTest
{
    #[Test]
    public function exposesTheConfiguredSource(): void
    {
        expect(new SymfonyPlugin(FixtureKernel::class)->source())->toBeNull();
        expect(new SymfonyPlugin(FixtureKernel::class, source: 'application')->source())->toBe('application');
    }

    #[Test]
    public function rejectsAnEmptySource(): void
    {
        expect()->calling(static fn(): SymfonyPlugin => new SymfonyPlugin(FixtureKernel::class, source: ''))
            ->toThrow(\InvalidArgumentException::class, message: 'Service source must not be empty.');
    }

    #[Test]
    public function aServiceWithoutAnIdUsesTheParameterType(): void
    {
        expect($this->plugin()->resolve(Greeter::class, [new Service()]))->toBeInstanceOf(Greeter::class);
    }

    #[Test]
    public function anExplicitIdEqualToTheMissingTypeFails(): void
    {
        $plugin = $this->plugin();

        expect()->calling(static fn(): ?object => $plugin->resolve(\ArrayObject::class, [new Service(\ArrayObject::class)]))
            ->toThrow(SymfonyBridgeError::class, matching: '/no service "ArrayObject"/');
    }

    #[Test]
    public function aServiceWithoutAnIdFailsWhenTheTypeIsMissing(): void
    {
        $plugin = $this->plugin();

        expect()->calling(static fn(): ?object => $plugin->resolve(\ArrayObject::class, [new Service()]))
            ->toThrow(SymfonyBridgeError::class, matching: '/no service "ArrayObject"/');
    }

    #[Test]
    public function resolvesContainerServicesByType(): void
    {
        $greeter = $this->plugin()->resolve(Greeter::class, []);

        expect($greeter)
            ->because('SymfonyPlugin::resolve() MUST return Greeter.')
            ->toBeInstanceOf(Greeter::class);

        expect($greeter->greet('Ada'))->because('resolves container services by type')->toBe('Hello, Ada!');
    }

    #[Test]
    public function resolvesPrivateServicesThroughTheTestContainer(): void
    {
        // VisitCounter is private and has no reference. Only the test container
        // keeps it available.
        expect($this->plugin()->resolve(VisitCounter::class, []))->because('resolves private services through the test container')
            ->toBeInstanceOf(VisitCounter::class);
    }

    #[Test]
    public function theServiceAttributeResolvesByExplicitId(): void
    {
        $named = $this->plugin()->resolve(NamedGreeter::class, [new Service('fixture.named_greeter')]);

        expect($named)->because('the service attribute resolves by explicit ID')->toBeInstanceOf(NamedGreeter::class);
    }

    #[Test]
    public function aTypeWithoutTheAttributeMissesIdOnlyServices(): void
    {
        expect($this->plugin()->resolve(NamedGreeter::class, []))->because('a type without the attribute misses ID only services')->toBeNull();
    }

    #[Test]
    public function aTypeTheContainerDoesNotKnowReturnsNull(): void
    {
        expect($this->plugin()->resolve(\ArrayObject::class, []))->because('a type the container does not know returns null')->toBeNull();
    }

    #[Test]
    public function anUnknownTypeFallsThroughToTheNextResolver(): void
    {
        $answer = new \ArrayObject();
        $later = new ServiceResolverProbe($answer);
        $scopes = new HarnessScopes([], [$this->plugin(), $later]);

        expect($scopes->resolve(\ArrayObject::class, 'test'))
            ->because('an unknown Symfony type MUST fall through to the next resolver')
            ->toBe($answer);
        expect($later->calls)->toBe(1);
    }

    #[Test]
    public function anUnknownExplicitServiceStopsTheResolverChain(): void
    {
        $later = new ServiceResolverProbe(new Greeter());
        $scopes = new HarnessScopes([], [$this->plugin(), $later]);

        expect()->calling(static fn(): object => $scopes->resolve(
            Greeter::class,
            'test',
            [new Service('fixture.missing')],
        ))
            ->because('an explicit Symfony service failure MUST stop the resolver chain')
            ->toThrow(ServiceResolutionFailed::class, matching: '/no service "fixture\.missing"/');
        expect($later->calls)->toBe(0);
    }

    #[Test]
    public function anUnknownExplicitIdFailsLoudly(): void
    {
        $plugin = $this->plugin();

        expect()->calling(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, [new Service('fixture.missing')]);
        })->because('an unknown explicit ID causes an error')->toThrow(SymfonyBridgeError::class, matching: '/no service "fixture\.missing".*Check the service ID/s');
    }

    #[Test]
    public function anExplicitIdOfTheWrongTypeFailsLoudly(): void
    {
        $plugin = $this->plugin();

        expect()->calling(static function () use ($plugin): void {
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

        expect()->calling(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->because('a kernel without the test container fails at boot')->toThrow(SymfonyBridgeError::class, matching: '/framework\.test/');
    }

    #[Test]
    public function aKernelWithoutServicesResetterFailsAtBoot(): void
    {
        $plugin = new SymfonyPlugin(static fn(): KernelInterface => BareKernel::withTestContainer());

        expect()->calling(static function () use ($plugin): void {
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

        expect($plugin->resolve(Greeter::class, []))->because('waiving resets accepts a kernel without the resetter')->toBeNull();
    }

    #[Test]
    public function waivedResetsLeaveStateInPlace(): void
    {
        $plugin = new SymfonyPlugin(FixtureKernel::class, env: 'test', debug: true, resetBetweenTests: false);
        $counter = $plugin->resolve(VisitCounter::class, []);

        expect($counter)
            ->because('SymfonyPlugin::resolve() MUST return VisitCounter.')
            ->toBeInstanceOf(VisitCounter::class);

        $counter->record();
        $plugin->afterTest($this->context(), $this->result());

        expect($counter->count())->because('waived resets leave state in place')->toBe(1);
    }

    #[Test]
    public function theKernelIsAPerWorkerHarnessServiceAndBootsOnce(): void
    {
        $plugin = $this->plugin();
        $definitions = $plugin->services();
        $definition = $definitions[0];

        $first = ($definition->factory)();

        expect($first)
            ->because('The Symfony harness factory MUST return KernelInterface.')
            ->toBeInstanceOf(KernelInterface::class);

        expect($definitions)->because('the kernel is a per run harness service and boots once')->toHaveCount(1);
        expect($definition->type)->toBe(KernelInterface::class);
        expect($definition->scope)->toBe(Scope::PerWorker);
        expect($first->getEnvironment())->toBe('test');
        expect(($definition->factory)())->toBe($first);
    }

    #[Test]
    public function aClosureFactoryBootsTheKernelItProduces(): void
    {
        $plugin = new SymfonyPlugin(static fn(): KernelInterface => new FixtureKernel('test', true));

        expect($plugin->resolve(Greeter::class, []))->because('a closure factory boots the kernel it produces')->toBeInstanceOf(Greeter::class);

        $invalid = new SymfonyPlugin(static fn(): object => new \stdClass()); // @phpstan-ignore argument.type (This test deliberately supplies an invalid factory result.)

        expect()->calling(static fn(): object => ($invalid->services()[0]->factory)())->toThrow(
            SymfonyBridgeError::class,
            matching: '/returned "stdClass".*KernelInterface/',
        );
    }

    #[Test]
    public function aClassThatIsNotAKernelFailsLoudly(): void
    {
        $plugin = new SymfonyPlugin(\ArrayObject::class); // @phpstan-ignore argument.type (This test deliberately supplies an invalid kernel class.)

        expect()->calling(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->because('a class that is not a kernel causes an error')->toThrow(SymfonyBridgeError::class, matching: '/does not implement/');
    }

    #[Test]
    public function afterTestResetsStatefulContainerServices(): void
    {
        $plugin = $this->plugin();
        $counter = $plugin->resolve(VisitCounter::class, []);

        expect($counter)
            ->because('SymfonyPlugin::resolve() MUST return VisitCounter.')
            ->toBeInstanceOf(VisitCounter::class);

        $counter->record();
        $counter->record();
        $result = $this->result();
        $returned = $plugin->afterTest($this->context(), $result);

        expect($counter->count())->because('after test resets stateful container services')->toBe(0);
        expect($returned)->toBe($result);
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

        expect($booted)->because('after test without a booted kernel is a no-op')->toBe(false);
        expect($returned)->toBe($result);
    }

    private function plugin(): SymfonyPlugin
    {
        return new SymfonyPlugin(FixtureKernel::class, env: 'test', debug: true);
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

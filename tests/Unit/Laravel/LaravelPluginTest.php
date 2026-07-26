<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\After;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Laravel\LaravelBridgeError;
use Greenlight\Laravel\LaravelPlugin;
use Greenlight\Laravel\Service;
use Greenlight\Plugin\TestContext;
use Greenlight\Tests\Fixture\Laravel\FixtureApplication;
use Greenlight\Tests\Fixture\Laravel\Greeter;
use Greenlight\Tests\Fixture\Laravel\NamedGreeter;
use Greenlight\Tests\Fixture\Laravel\VisitCounter;
use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;

#[SkipUnless(ClassAvailable::class, \Illuminate\Foundation\Application::class)]
final class LaravelPluginTest
{
    /** A failed expectation must not leak Laravel statics into other tests. */
    #[After]
    public function forgetLaravelStatics(): void
    {
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance();
    }

    #[Test]
    public function resolvesContainerServicesByType(): void
    {
        $greeter = $this->plugin()->resolve(Greeter::class, []);

        if (!$greeter instanceof Greeter) {
            Fail::because(\sprintf(
                'Expected LaravelPlugin::resolve() to return Greeter, got %s.',
                \get_debug_type($greeter),
            ));
        }

        Expect::that($greeter->greet('Ada'))->toBe('Hello, Ada!');
    }

    #[Test]
    public function resolvesTheSameSingletonWithinOneApplication(): void
    {
        $plugin = $this->plugin();

        Expect::that($plugin->resolve(VisitCounter::class, []))
            ->toBe($plugin->resolve(VisitCounter::class, []));
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
    public function anUnboundClassIsNotImplicitlyResolved(): void
    {
        // Laravel could construct ArrayObject through implicit resolution.
        // The bridge only serves explicit bindings.
        Expect::that($this->plugin()->resolve(\ArrayObject::class, []))->toBeNull();
    }

    #[Test]
    public function anUnknownExplicitIdFailsLoudly(): void
    {
        $plugin = $this->plugin();

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, [new Service('fixture.missing')]);
        })->toThrow(LaravelBridgeError::class, matching: '/no binding "fixture\.missing".*Check the id for typos/s');
    }

    #[Test]
    public function anExplicitIdOfTheWrongTypeFailsLoudly(): void
    {
        $plugin = $this->plugin();

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(VisitCounter::class, [new Service('fixture.named_greeter')]);
        })->toThrow(LaravelBridgeError::class, matching: '/is an instance of .* but the parameter declares/');
    }

    #[Test]
    public function aBootstrapFileThatDoesNotExistFailsLoudly(): void
    {
        $plugin = new LaravelPlugin($this->fixtureDir() . '/missing-bootstrap.php');

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->toThrow(LaravelBridgeError::class, matching: '/does not exist.*bootstrap\/app\.php/s');
    }

    #[Test]
    public function aBootstrapThatDoesNotReturnAnApplicationFailsLoudly(): void
    {
        $plugin = new LaravelPlugin($this->fixtureDir() . '/bootstrap-invalid.php');

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->toThrow(LaravelBridgeError::class, matching: '/returned "stdClass".*Application::configure/s');
    }

    #[Test]
    public function theApplicationIsAPerTestHarnessServiceWhenRefreshing(): void
    {
        $plugin = $this->plugin();
        $definitions = $plugin->services();
        $definition = $definitions[0];

        $first = ($definition->factory)();

        if (!$first instanceof Application) {
            Fail::because(\sprintf(
                'Expected the Laravel harness factory to return the application, got %s.',
                \get_debug_type($first),
            ));
        }

        Expect::that($definitions)->toHaveCount(1)
            ->and($definition->type)->toBe(Application::class)
            ->and($definition->scope)->toBe(Scope::PerTest)
            ->and(($definition->factory)())->toBe($first);
    }

    #[Test]
    public function theApplicationIsAPerRunHarnessServiceWithoutRefresh(): void
    {
        $plugin = new LaravelPlugin($this->fixtureDir() . '/bootstrap.php', refreshBetweenTests: false);

        Expect::that($plugin->services()[0]->scope)->toBe(Scope::PerRun);
    }

    #[Test]
    public function aClosureFactoryBootsTheApplicationItProduces(): void
    {
        $plugin = new LaravelPlugin(static fn(): Application => FixtureApplication::create());

        Expect::that($plugin->resolve(Greeter::class, []))->toBeInstanceOf(Greeter::class);
    }

    #[Test]
    public function theApplicationEnvironmentComesFromTheEnvParameter(): void
    {
        $app = $this->plugin()->resolve(Application::class, []);

        if (!$app instanceof Application) {
            Fail::because(\sprintf(
                'Expected LaravelPlugin::resolve() to return the application, got %s.',
                \get_debug_type($app),
            ));
        }

        Expect::that($app->environment())->toBe('testing');
    }

    #[Test]
    public function theConcreteApplicationTypeResolvesThroughContainerAliases(): void
    {
        $plugin = $this->plugin();
        $app = ($plugin->services()[0]->factory)();

        Expect::that($plugin->resolve(\Illuminate\Foundation\Application::class, []))->toBe($app);
    }

    #[Test]
    public function afterTestBootsAFreshApplicationForTheNextTest(): void
    {
        $plugin = $this->plugin();
        $counter = $plugin->resolve(VisitCounter::class, []);

        if (!$counter instanceof VisitCounter) {
            Fail::because(\sprintf(
                'Expected LaravelPlugin::resolve() to return VisitCounter, got %s.',
                \get_debug_type($counter),
            ));
        }

        $counter->record();
        $result = $this->result();
        $returned = $plugin->afterTest($this->context(), $result);
        $second = $plugin->resolve(VisitCounter::class, []);

        if (!$second instanceof VisitCounter) {
            Fail::because(\sprintf(
                'Expected LaravelPlugin::resolve() to return VisitCounter, got %s.',
                \get_debug_type($second),
            ));
        }

        Expect::that($returned)->toBe($result)
            ->and($second->count())->toBe(0)
            ->and($second === $counter)->toBe(false);
    }

    #[Test]
    public function waivedRefreshKeepsTheApplicationAndItsState(): void
    {
        $plugin = new LaravelPlugin($this->fixtureDir() . '/bootstrap.php', refreshBetweenTests: false);
        $counter = $plugin->resolve(VisitCounter::class, []);

        if (!$counter instanceof VisitCounter) {
            Fail::because(\sprintf(
                'Expected LaravelPlugin::resolve() to return VisitCounter, got %s.',
                \get_debug_type($counter),
            ));
        }

        $counter->record();
        $plugin->afterTest($this->context(), $this->result());

        Expect::that($counter->count())->toBe(1)
            ->and($plugin->resolve(VisitCounter::class, []))->toBe($counter);
    }

    #[Test]
    public function afterTestWithoutABootedApplicationIsANoOp(): void
    {
        $booted = false;
        $plugin = new LaravelPlugin(static function () use (&$booted): Application {
            $booted = true;

            return FixtureApplication::create();
        });

        $result = $this->result();
        $returned = $plugin->afterTest($this->context(), $result);

        Expect::that($booted)->toBe(false)->and($returned)->toBe($result);
    }

    #[Test]
    public function afterTestClearsFacadeAndContainerStatics(): void
    {
        $plugin = $this->plugin();
        $app = ($plugin->services()[0]->factory)();

        Expect::that(Facade::getFacadeApplication())->toBe($app)
            ->and(Container::getInstance())->toBe($app);

        $plugin->afterTest($this->context(), $this->result());

        Expect::that(Facade::getFacadeApplication())->toBeNull()
            ->and(Container::getInstance() === $app)->toBe(false);
    }

    #[Test]
    public function bootLeavesTheGlobalHandlerStackUnchanged(): void
    {
        $errorBefore = \set_error_handler(null);
        \restore_error_handler();
        $exceptionBefore = \set_exception_handler(null);
        \restore_exception_handler();

        $this->plugin()->resolve(Greeter::class, []);

        $errorAfter = \set_error_handler(null);
        \restore_error_handler();
        $exceptionAfter = \set_exception_handler(null);
        \restore_exception_handler();

        Expect::that($errorAfter)->toBe($errorBefore)
            ->and($exceptionAfter)->toBe($exceptionBefore);
    }

    private function plugin(): LaravelPlugin
    {
        return new LaravelPlugin($this->fixtureDir() . '/bootstrap.php');
    }

    private function fixtureDir(): string
    {
        return \dirname(__DIR__, 2) . '/Fixture/Laravel';
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

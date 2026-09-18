<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\Service;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Laravel\LaravelBridgeError;
use Greenlight\Laravel\LaravelFrameworkRequirement;
use Greenlight\Laravel\LaravelPlugin;
use Greenlight\Plugin\TestContext;
use Greenlight\Result\TestResult;
use Greenlight\Sandbox\EnvironmentVariables;
use Greenlight\Tests\Fixture\Laravel\FixtureApplication;
use Greenlight\Tests\Fixture\Laravel\Greeter;
use Greenlight\Tests\Fixture\Laravel\NamedGreeter;
use Greenlight\Tests\Fixture\Laravel\VisitCounter;
use Greenlight\Tests\Support\FilesystemRestriction;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\PluginLifecycle;
use Greenlight\Tests\Support\ServiceResolverProbe;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Support\Facades\Facade;

use function Greenlight\expect;

#[SkipUnless(ClassAvailable::class, LaravelApplication::class)]
final class LaravelPluginTest
{
    /**
     * @var list<LaravelPlugin>
     */
    private array $plugins = [];

    public function __construct(
        private readonly EnvironmentVariables $environment,
        private readonly Doubles $doubles,
    ) {}

    #[Test]
    public function exposesTheConfiguredSource(): void
    {
        expect(new LaravelPlugin('/project/bootstrap/app.php')->source())->toBeNull();
        expect(new LaravelPlugin('/project/bootstrap/app.php', source: 'application')->source())->toBe('application');
    }

    #[Test]
    public function rejectsAnEmptySource(): void
    {
        expect()->calling(static fn(): LaravelPlugin => new LaravelPlugin('/project/bootstrap/app.php', source: ''))
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
            ->toThrow(LaravelBridgeError::class, matching: '/no binding "ArrayObject"/');
    }

    #[Test]
    public function aServiceWithoutAnIdFailsWhenTheTypeIsMissing(): void
    {
        $plugin = $this->plugin();

        expect()->calling(static fn(): ?object => $plugin->resolve(\ArrayObject::class, [new Service()]))
            ->toThrow(LaravelBridgeError::class, matching: '/no binding "ArrayObject"/');
    }

    /** A failed expectation MUST NOT leak a Laravel application into another test. */
    #[After]
    public function releaseLaravelApplications(): void
    {
        foreach ($this->plugins as $plugin) {
            $plugin->afterTest($this->context(), $this->result());
        }

        $this->plugins = [];
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance();
    }

    #[Test]
    public function resolvesContainerServicesByType(): void
    {
        $greeter = $this->plugin()->resolve(Greeter::class, []);

        expect($greeter)
            ->because('LaravelPlugin::resolve() MUST return Greeter.')
            ->toBeInstanceOf(Greeter::class);

        expect($greeter->greet('Ada'))->toBe('Hello, Ada!');
    }

    #[Test]
    public function resolvesTheSameSingletonWithinOneApplication(): void
    {
        $plugin = $this->plugin();

        expect($plugin->resolve(VisitCounter::class, []))
            ->toBe($plugin->resolve(VisitCounter::class, []));
    }

    #[Test]
    public function theServiceAttributeResolvesByExplicitId(): void
    {
        $named = $this->plugin()->resolve(NamedGreeter::class, [new Service('fixture.named_greeter')]);

        expect($named)->toBeInstanceOf(NamedGreeter::class);
    }

    #[Test]
    public function aTypeWithoutTheAttributeMissesIdOnlyServices(): void
    {
        expect($this->plugin()->resolve(NamedGreeter::class, []))->toBeNull();
    }

    #[Test]
    public function anUnboundClassIsNotImplicitlyResolved(): void
    {
        // Laravel could construct ArrayObject through implicit resolution.
        // The bridge only serves explicit bindings.
        expect($this->plugin()->resolve(\ArrayObject::class, []))->toBeNull();
    }

    #[Test]
    public function anUnboundTypeFallsThroughToTheNextResolver(): void
    {
        $answer = new \ArrayObject();
        $later = new ServiceResolverProbe($answer);
        $scopes = new HarnessScopes([], [$this->plugin(), $later]);

        expect($scopes->resolve(\ArrayObject::class, 'test'))
            ->because('an unbound Laravel type MUST fall through to the next resolver')
            ->toBe($answer);
        expect($later->calls)->toBe(1);
    }

    #[Test]
    public function anUnknownExplicitBindingStopsTheResolverChain(): void
    {
        $later = new ServiceResolverProbe(new Greeter());
        $scopes = new HarnessScopes([], [$this->plugin(), $later]);

        expect()->calling(static fn(): object => $scopes->resolve(
            Greeter::class,
            'test',
            [new Service('fixture.missing')],
        ))
            ->because('an explicit Laravel binding failure MUST stop the resolver chain')
            ->toThrow(ServiceResolutionFailed::class, matching: '/no binding "fixture\.missing"/');
        expect($later->calls)->toBe(0);
    }

    #[Test]
    public function anUnknownExplicitIdFailsLoudly(): void
    {
        $plugin = $this->plugin();

        expect()->calling(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, [new Service('fixture.missing')]);
        })->toThrow(LaravelBridgeError::class, matching: '/no binding "fixture\.missing".*Check the id for typos/s');
    }

    #[Test]
    public function anExplicitIdOfTheWrongTypeFailsLoudly(): void
    {
        $plugin = $this->plugin();

        expect()->calling(static function () use ($plugin): void {
            $plugin->resolve(VisitCounter::class, [new Service('fixture.named_greeter')]);
        })->toThrow(LaravelBridgeError::class, matching: '/is an instance of .* but the parameter declares/');
    }

    #[Test]
    public function aBootstrapFileThatDoesNotExistFailsLoudly(): void
    {
        $this->environment->set('APP_ENV', 'before-laravel');
        $plugin = $this->track(new LaravelPlugin($this->fixtureDir() . '/missing-bootstrap.php'));

        expect()->calling(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->toThrow(LaravelBridgeError::class, matching: '/does not exist.*bootstrap\/app\.php/s');
        expect(\getenv('APP_ENV'))->toBe('before-laravel');
        expect($_ENV['APP_ENV'] ?? null)->toBe('before-laravel');
        expect($_SERVER['APP_ENV'] ?? null)->toBe('before-laravel');
    }

    #[Test]
    #[Isolated]
    public function aRestrictedBootstrapFileFailsWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 3);
        $bootstrap = \dirname($root) . '/bootstrap/app.php';
        FilesystemRestriction::toProject($root);

        $plugin = $this->track(new LaravelPlugin($bootstrap));
        expect()->calling(
            static function () use ($plugin, &$warning): void {
                ErrorTrap::run(
                    static fn() => $plugin->resolve(Greeter::class, []),
                    $warning,
                );
            },
        )->because('a restricted Laravel bootstrap file causes a bridge error')
            ->toThrow(LaravelBridgeError::class, matching: '/does not exist.*bootstrap\/app\.php/s');
        expect($warning)
            ->because('a restricted Laravel bootstrap file MUST not leak engine diagnostics')
            ->toBeNull();
    }

    #[Test]
    public function aComponentOnlyIlluminateInstallationCannotUseTheBridge(): void
    {
        expect()->calling(static function (): void {
            LaravelFrameworkRequirement::checkVersion(null);
        })->toThrow(
            LaravelBridgeError::class,
            matching: '/requires the complete laravel\\/framework 13 package/',
        );
    }

    #[Test]
    public function anUnsupportedLaravelMajorVersionCannotUseTheBridge(): void
    {
        expect()->calling(static function (): void {
            LaravelFrameworkRequirement::checkVersion('12.9.0');
        })->toThrow(
            LaravelBridgeError::class,
            matching: '/found laravel\\/framework "12\\.9\\.0".*requires major version 13/s',
        );
    }

    #[Test]
    public function aBootstrapThatDoesNotReturnAnApplicationFailsLoudly(): void
    {
        $plugin = $this->track(new LaravelPlugin($this->fixtureDir() . '/bootstrap-invalid.php'));

        expect()->calling(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->toThrow(LaravelBridgeError::class, matching: '/returned "stdClass".*Application::configure/s');
    }

    #[Test]
    public function aClosureThatDoesNotReturnAnApplicationFailsLoudly(): void
    {
        $plugin = $this->track(new LaravelPlugin(
            static fn(): \stdClass => new \stdClass(), // @phpstan-ignore argument.type (This test deliberately supplies an invalid application factory.)
        ));

        expect()->calling(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->toThrow(LaravelBridgeError::class, matching: '/returned "stdClass".*Application::configure/s');
    }

    #[Test]
    public function anApplicationWithoutAConsoleKernelFailsLoudly(): void
    {
        $plugin = $this->track(new LaravelPlugin(
            fn(): Application => $this->bareApplication(),
        ));

        expect()->calling(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->toThrow(LaravelBridgeError::class, matching: '/no console kernel binding/');
    }

    #[Test]
    public function aConsoleKernelBindingOfTheWrongTypeFailsLoudly(): void
    {
        $plugin = $this->track(new LaravelPlugin(function (): Application {
            $app = $this->bareApplication();
            $app->instance(Kernel::class, new \stdClass());

            return $app;
        }));

        expect()->calling(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->toThrow(LaravelBridgeError::class, matching: '/contains "stdClass" instead of/');
    }

    #[Test]
    public function aFailedKernelBootstrapRestoresProcessState(): void
    {
        $this->environment->set('APP_ENV', 'before-laravel');
        $container = Container::getInstance();
        $failure = new \RuntimeException('The kernel could not bootstrap.');
        $kernel = $this->doubles->mock(Kernel::class, static function (MockPlan $plan) use ($failure): void {
            $plan->expects('bootstrap')->once()->andThrows($failure);
        });
        $plugin = $this->track(new LaravelPlugin(function () use ($kernel): Application {
            $app = $this->bareApplication();
            $app->instance(Kernel::class, $kernel);

            return $app;
        }));

        $error = null;

        try {
            $plugin->resolve(Greeter::class, []);
        } catch (LaravelBridgeError $caught) {
            $error = $caught;
        }
        expect($error)
            ->because('the failed kernel bootstrap MUST cause a Laravel bridge error')
            ->toBeInstanceOf(LaravelBridgeError::class);
        expect($error->getPrevious())
            ->because('the resolution failure MUST keep the container cause')
            ->toBe($failure);
        expect(Container::getInstance())->toBe($container);
        expect(\getenv('APP_ENV'))->toBe('before-laravel');
        expect($_ENV['APP_ENV'] ?? null)->toBe('before-laravel');
        expect($_SERVER['APP_ENV'] ?? null)->toBe('before-laravel');
    }

    #[Test]
    public function theApplicationIsAPerTestHarnessServiceWhenRefreshing(): void
    {
        $plugin = $this->plugin();
        $definitions = $plugin->services();
        $definition = $definitions[0];

        $first = ($definition->factory)();

        expect($first)
            ->because('The Laravel harness factory MUST return the application.')
            ->toBeInstanceOf(Application::class);

        expect($definitions)->toHaveCount(1);
        expect($definition->type)->toBe(Application::class);
        expect($definition->scope)->toBe(Scope::PerTest);
        expect(($definition->factory)())->toBe($first);
    }

    #[Test]
    public function theApplicationIsAPerWorkerHarnessServiceWithoutRefresh(): void
    {
        $plugin = $this->track(new LaravelPlugin(
            $this->fixtureDir() . '/bootstrap.php',
            refreshBetweenTests: false,
        ));

        expect($plugin->services()[0]->scope)->toBe(Scope::PerWorker);
    }

    #[Test]
    public function aClosureFactoryBootsTheApplicationItProduces(): void
    {
        $plugin = $this->track(new LaravelPlugin(
            static fn(): Application => FixtureApplication::create(),
        ));

        expect($plugin->resolve(Greeter::class, []))->toBeInstanceOf(Greeter::class);
    }

    #[Test]
    public function theApplicationEnvironmentComesFromTheEnvParameter(): void
    {
        $app = $this->plugin()->resolve(Application::class, []);

        expect($app)
            ->because('LaravelPlugin::resolve() MUST return the application.')
            ->toBeInstanceOf(Application::class);

        expect($app->environment())->toBe('testing');
    }

    #[Test]
    public function theConcreteApplicationTypeResolvesThroughContainerAliases(): void
    {
        $plugin = $this->plugin();
        $app = ($plugin->services()[0]->factory)();

        expect($plugin->resolve(LaravelApplication::class, []))->toBe($app);
    }

    #[Test]
    public function afterTestBootsAFreshApplicationForTheNextTest(): void
    {
        $plugin = $this->plugin();
        $counter = $plugin->resolve(VisitCounter::class, []);

        expect($counter)
            ->because('LaravelPlugin::resolve() MUST return VisitCounter.')
            ->toBeInstanceOf(VisitCounter::class);

        $counter->record();
        $result = $this->result();
        $returned = $plugin->afterTest($this->context(), $result);
        $second = $plugin->resolve(VisitCounter::class, []);

        expect($second)
            ->because('LaravelPlugin::resolve() MUST return VisitCounter.')
            ->toBeInstanceOf(VisitCounter::class);

        expect($returned)->toBe($result);
        expect($second->count())->toBe(0);
        expect($second === $counter)->toBe(false);
    }

    #[Test]
    public function waivedRefreshKeepsTheApplicationAndItsState(): void
    {
        $this->environment->set('APP_ENV', 'before-laravel');
        $plugin = $this->track(new LaravelPlugin(
            $this->fixtureDir() . '/bootstrap.php',
            refreshBetweenTests: false,
        ));
        $counter = $plugin->resolve(VisitCounter::class, []);

        expect($counter)
            ->because('LaravelPlugin::resolve() MUST return VisitCounter.')
            ->toBeInstanceOf(VisitCounter::class);

        $counter->record();
        $plugin->afterTest($this->context(), $this->result());

        expect($counter->count())->toBe(1);
        expect($plugin->resolve(VisitCounter::class, []))->toBe($counter);
    }

    #[Test]
    public function afterTestWithoutABootedApplicationIsANoOp(): void
    {
        $booted = false;
        $plugin = $this->track(new LaravelPlugin(static function () use (&$booted): Application {
            $booted = true;

            return FixtureApplication::create();
        }));

        $result = $this->result();
        $returned = $plugin->afterTest($this->context(), $result);

        expect($booted)->toBe(false);
        expect($returned)->toBe($result);
    }

    #[Test]
    public function afterTestClearsFacadeAndContainerStatics(): void
    {
        $this->environment->set('APP_ENV', 'before-laravel');
        $container = Container::getInstance();
        $plugin = $this->plugin();
        $app = ($plugin->services()[0]->factory)();

        expect(Facade::getFacadeApplication())->toBe($app);
        expect(Container::getInstance())->toBe($app);

        $plugin->afterTest($this->context(), $this->result());

        expect(Facade::getFacadeApplication())->toBeNull();
        expect(Container::getInstance())->toBe($container);
        expect(\getenv('APP_ENV'))->toBe('before-laravel');
        expect($_ENV['APP_ENV'] ?? null)->toBe('before-laravel');
        expect($_SERVER['APP_ENV'] ?? null)->toBe('before-laravel');
    }

    #[Test]
    public function afterTestRemovesAnEnvironmentThatWasInitiallyAbsent(): void
    {
        $this->environment->unset('APP_ENV');
        $plugin = $this->plugin();

        $plugin->resolve(Greeter::class, []);

        expect(\getenv('APP_ENV'))
            ->because('Laravel boot MUST set the configured application environment')
            ->toBe('testing');
        expect($_ENV['APP_ENV'] ?? null)
            ->toBe('testing');
        expect($_SERVER['APP_ENV'] ?? null)
            ->toBe('testing');

        $plugin->afterTest($this->context(), $this->result());

        expect(\getenv('APP_ENV'))
            ->because('application release MUST remove an environment that was initially absent')
            ->toBeFalse();
        expect(\array_key_exists('APP_ENV', $_ENV))
            ->toBeFalse();
        expect(\array_key_exists('APP_ENV', $_SERVER))
            ->toBeFalse();
    }

    #[Test]
    public function bootLeavesTheGlobalHandlerStackUnchanged(): void
    {
        $errorBefore = \set_error_handler(null);
        \restore_error_handler();
        $exceptionBefore = \set_exception_handler(null);
        \restore_exception_handler();
        $reportingBefore = \error_reporting();

        $this->plugin()->resolve(Greeter::class, []);

        $errorAfter = \set_error_handler(null);
        \restore_error_handler();
        $exceptionAfter = \set_exception_handler(null);
        \restore_exception_handler();

        expect($errorAfter)->toBe($errorBefore);
        expect($exceptionAfter)->toBe($exceptionBefore);
        expect(\error_reporting())->toBe($reportingBefore);
    }

    #[Test]
    public function repeatedApplicationRefreshesKeepMemoryFlat(): void
    {
        $plugin = $this->plugin();

        for ($index = 0; $index < 10; ++$index) {
            $this->refreshApplication($plugin);
        }

        \gc_collect_cycles();
        $memoryBefore = \memory_get_usage();

        for ($index = 0; $index < 60; ++$index) {
            $this->refreshApplication($plugin);
        }

        \gc_collect_cycles();

        expect(\memory_get_usage() - $memoryBefore)->toBeLessThan(262_144);
    }

    private function plugin(): LaravelPlugin
    {
        return $this->track(new LaravelPlugin($this->fixtureDir() . '/bootstrap.php'));
    }

    private function track(LaravelPlugin $plugin): LaravelPlugin
    {
        $this->plugins[] = $plugin;

        return $plugin;
    }

    private function bareApplication(): LaravelApplication
    {
        return new LaravelApplication($this->fixtureDir());
    }

    private function refreshApplication(LaravelPlugin $plugin): void
    {
        $plugin->resolve(Greeter::class, []);
        $plugin->afterTest($this->context(), $this->result());
    }

    private function fixtureDir(): string
    {
        return FixturePath::get('Laravel');
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

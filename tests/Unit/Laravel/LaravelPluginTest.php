<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Condition\ClassAvailable;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Result\TestResult;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Harness\Scope;
use Greenlight\Laravel\LaravelBridgeError;
use Greenlight\Laravel\LaravelFrameworkRequirement;
use Greenlight\Laravel\LaravelPlugin;
use Greenlight\Laravel\Service;
use Greenlight\Plugin\TestContext;
use Greenlight\Tests\Fixture\Laravel\FixtureApplication;
use Greenlight\Tests\Fixture\Laravel\Greeter;
use Greenlight\Tests\Fixture\Laravel\NamedGreeter;
use Greenlight\Tests\Fixture\Laravel\VisitCounter;
use Greenlight\Tests\Support\FilesystemRestriction;
use Greenlight\Tests\Support\PluginLifecycle;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Application as LaravelApplication;
use Illuminate\Support\Facades\Facade;

#[SkipUnless(ClassAvailable::class, LaravelApplication::class)]
final class LaravelPluginTest
{
    /**
     * @var list<LaravelPlugin>
     */
    private array $plugins = [];

    public function __construct(
        private readonly EnvironmentSandbox $environment,
        private readonly Doubles $doubles,
    ) {}

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
    public function beforeTestPreservesLazyApplicationBoot(): void
    {
        $factoryCalled = false;
        $plugin = new LaravelPlugin(static function () use (&$factoryCalled): Application {
            $factoryCalled = true;

            Fail::because('LaravelPlugin::beforeTest() MUST NOT boot the application.');
        });

        $plugin->beforeTest($this->context());

        Expect::that($factoryCalled)
            ->because('the application remains lazy during beforeTest()')
            ->toBeFalse();
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
        $this->environment->set('APP_ENV', 'before-laravel');
        $plugin = $this->track(new LaravelPlugin($this->fixtureDir() . '/missing-bootstrap.php'));

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->toThrow(LaravelBridgeError::class, matching: '/does not exist.*bootstrap\/app\.php/s');
        Expect::that(\getenv('APP_ENV'))->toBe('before-laravel');
        Expect::that($_ENV['APP_ENV'] ?? null)->toBe('before-laravel');
        Expect::that($_SERVER['APP_ENV'] ?? null)->toBe('before-laravel');
    }

    #[Test]
    #[Isolated]
    public function aRestrictedBootstrapFileFailsWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 3);
        $bootstrap = \dirname($root) . '/bootstrap/app.php';
        FilesystemRestriction::toProject($root);

        $plugin = $this->track(new LaravelPlugin($bootstrap));
        Expect::that(
            static function () use ($plugin, &$warning): void {
                ErrorTrap::run(
                    static fn(): ?object => $plugin->resolve(Greeter::class, []),
                    $warning,
                );
            },
        )->because('a restricted Laravel bootstrap file causes a bridge error')
            ->toThrow(LaravelBridgeError::class, matching: '/does not exist.*bootstrap\/app\.php/s');
        Expect::that($warning)
            ->because('a restricted Laravel bootstrap file MUST not leak engine diagnostics')
            ->toBeNull();
    }

    #[Test]
    public function aComponentOnlyIlluminateInstallationCannotUseTheBridge(): void
    {
        Expect::that(static function (): void {
            LaravelFrameworkRequirement::checkVersion(null);
        })->toThrow(
            LaravelBridgeError::class,
            matching: '/requires the complete laravel\\/framework 13 package/',
        );
    }

    #[Test]
    public function anUnsupportedLaravelMajorVersionCannotUseTheBridge(): void
    {
        Expect::that(static function (): void {
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

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->toThrow(LaravelBridgeError::class, matching: '/returned "stdClass".*Application::configure/s');
    }

    #[Test]
    public function anApplicationWithoutAConsoleKernelFailsLoudly(): void
    {
        $plugin = $this->track(new LaravelPlugin(
            fn(): Application => $this->bareApplication(),
        ));

        Expect::that(static function () use ($plugin): void {
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

        Expect::that(static function () use ($plugin): void {
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

        Expect::that(static function () use ($plugin): void {
            $plugin->resolve(Greeter::class, []);
        })->toThrow($failure);
        Expect::that(Container::getInstance())->toBe($container);
        Expect::that(\getenv('APP_ENV'))->toBe('before-laravel');
        Expect::that($_ENV['APP_ENV'] ?? null)->toBe('before-laravel');
        Expect::that($_SERVER['APP_ENV'] ?? null)->toBe('before-laravel');
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

        Expect::that($definitions)->toHaveCount(1);
        Expect::that($definition->type)->toBe(Application::class);
        Expect::that($definition->scope)->toBe(Scope::PerTest);
        Expect::that(($definition->factory)())->toBe($first);
    }

    #[Test]
    public function theApplicationIsAPerRunHarnessServiceWithoutRefresh(): void
    {
        $plugin = $this->track(new LaravelPlugin(
            $this->fixtureDir() . '/bootstrap.php',
            refreshBetweenTests: false,
        ));

        Expect::that($plugin->services()[0]->scope)->toBe(Scope::PerRun);
    }

    #[Test]
    public function aClosureFactoryBootsTheApplicationItProduces(): void
    {
        $plugin = $this->track(new LaravelPlugin(
            static fn(): Application => FixtureApplication::create(),
        ));

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

        Expect::that($plugin->resolve(LaravelApplication::class, []))->toBe($app);
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

        Expect::that($returned)->toBe($result);
        Expect::that($second->count())->toBe(0);
        Expect::that($second === $counter)->toBe(false);
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

        if (!$counter instanceof VisitCounter) {
            Fail::because(\sprintf(
                'Expected LaravelPlugin::resolve() to return VisitCounter, got %s.',
                \get_debug_type($counter),
            ));
        }

        $counter->record();
        $plugin->afterTest($this->context(), $this->result());

        Expect::that($counter->count())->toBe(1);
        Expect::that($plugin->resolve(VisitCounter::class, []))->toBe($counter);
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

        Expect::that($booted)->toBe(false);
        Expect::that($returned)->toBe($result);
    }

    #[Test]
    public function afterTestClearsFacadeAndContainerStatics(): void
    {
        $this->environment->set('APP_ENV', 'before-laravel');
        $container = Container::getInstance();
        $plugin = $this->plugin();
        $app = ($plugin->services()[0]->factory)();

        Expect::that(Facade::getFacadeApplication())->toBe($app);
        Expect::that(Container::getInstance())->toBe($app);

        $plugin->afterTest($this->context(), $this->result());

        Expect::that(Facade::getFacadeApplication())->toBeNull();
        Expect::that(Container::getInstance())->toBe($container);
        Expect::that(\getenv('APP_ENV'))->toBe('before-laravel');
        Expect::that($_ENV['APP_ENV'] ?? null)->toBe('before-laravel');
        Expect::that($_SERVER['APP_ENV'] ?? null)->toBe('before-laravel');
    }

    #[Test]
    public function afterTestRemovesAnEnvironmentThatWasInitiallyAbsent(): void
    {
        $this->environment->unset('APP_ENV');
        $plugin = $this->plugin();

        $plugin->resolve(Greeter::class, []);

        Expect::that(\getenv('APP_ENV'))
            ->because('Laravel boot MUST set the configured application environment')
            ->toBe('testing');
        Expect::that($_ENV['APP_ENV'] ?? null)
            ->toBe('testing');
        Expect::that($_SERVER['APP_ENV'] ?? null)
            ->toBe('testing');

        $plugin->afterTest($this->context(), $this->result());

        Expect::that(\getenv('APP_ENV'))
            ->because('application release MUST remove an environment that was initially absent')
            ->toBeFalse();
        Expect::that(\array_key_exists('APP_ENV', $_ENV))
            ->toBeFalse();
        Expect::that(\array_key_exists('APP_ENV', $_SERVER))
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

        Expect::that($errorAfter)->toBe($errorBefore);
        Expect::that($exceptionAfter)->toBe($exceptionBefore);
        Expect::that(\error_reporting())->toBe($reportingBefore);
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

        Expect::that(\memory_get_usage() - $memoryBefore)->toBeLessThan(262_144);
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
        return \dirname(__DIR__, 2) . '/Fixture/Laravel';
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

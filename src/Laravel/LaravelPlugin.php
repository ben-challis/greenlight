<?php

declare(strict_types=1);

namespace Greenlight\Laravel;

use Greenlight\Core\Result\TestResult;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\TestLifecycleSubscriber;
use Illuminate\Container\Container;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Facade;

/**
 * The application boots lazily and lives for one test, so container state,
 * facade roots, and singletons cannot carry over between tests.
 *
 * Only bound services resolve. The bridge does not use Laravel's implicit
 * resolution for unbound classes. #[Service] overrides type-based lookup
 * with an explicit binding id. External resources must be isolated by
 * GREENLIGHT_CHANNEL.
 */
final class LaravelPlugin implements HarnessProvider, ServiceResolver, TestLifecycleSubscriber
{
    /**
     * @var \Closure(): mixed
     */
    private readonly \Closure $factory;

    /**
     * A constructor injection that fails after boot leaves the application
     * here for one test. The next completed test's afterTest flushes it.
     */
    private ?Application $app = null;

    /**
     * @param string|\Closure(): Application $application
     *   A path to the file that returns the application, usually
     *   bootstrap/app.php, or a closure returning the application when
     *   exotic construction is needed.
     * @param non-empty-string $env
     * @param bool $refreshBetweenTests
     *   Set to false only when no service carries state; tests on one worker
     *   then share one unreset application for the worker lifetime.
     */
    public function __construct(
        string|\Closure $application,
        private readonly string $env = 'testing',
        private readonly bool $refreshBetweenTests = true,
    ) {
        $this->factory = $application instanceof \Closure
            ? $application
            : static function () use ($application): mixed {
                if (!\is_file($application)) {
                    throw LaravelBridgeError::bootstrapFileMissing($application);
                }

                return require $application;
            };
    }

    /**
     * @return list<ServiceDefinition>
     */
    #[\Override]
    public function services(): array
    {
        return [
            new ServiceDefinition(
                Application::class,
                $this->refreshBetweenTests ? Scope::PerTest : Scope::PerRun,
                $this->application(...),
            ),
        ];
    }

    /**
     * @param class-string $type
     * @param list<object> $attributes
     */
    #[\Override]
    public function resolve(string $type, array $attributes): ?object
    {
        $id = $type;

        foreach ($attributes as $attribute) {
            if ($attribute instanceof Service) {
                $id = $attribute->id;
            }
        }

        $app = $this->application();

        if (!$app->bound($id)) {
            if ($id !== $type) {
                throw LaravelBridgeError::unknownServiceId($id, $type);
            }

            return null;
        }

        $service = $app->make($id);

        if (!$service instanceof $type) {
            throw LaravelBridgeError::serviceTypeMismatch($id, $type, \get_debug_type($service));
        }

        return $service;
    }

    #[\Override]
    public function beforeTest(TestContext $context): void {}

    #[\Override]
    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        if (!$this->refreshBetweenTests || !$this->app instanceof Application) {
            return $result;
        }

        $app = $this->app;
        // Cleared first so a throwing teardown cannot leak a half-dead application.
        $this->app = null;

        $app->flush();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance();

        return $result;
    }

    /** An invalid application is not cached, so each use fails instead of running unisolated. */
    private function application(): Application
    {
        if ($this->app instanceof Application) {
            return $this->app;
        }

        // Applied before every boot. Laravel loads .env without a change to
        // existing environment variables, so this value wins.
        $_ENV['APP_ENV'] = $this->env;
        $_SERVER['APP_ENV'] = $this->env;
        \putenv('APP_ENV=' . $this->env);

        $app = ($this->factory)();

        if (!$app instanceof Application) {
            throw LaravelBridgeError::notAnApplication(\get_debug_type($app));
        }

        if (!$app->bound(Kernel::class)) {
            throw LaravelBridgeError::consoleKernelUnavailable();
        }

        $app->make(Kernel::class)->bootstrap();

        // The boot pushed one Laravel error handler and one exception handler
        // above Greenlight's capture handlers. Pop both so Greenlight keeps
        // ownership of diagnostics.
        \restore_error_handler();
        \restore_exception_handler();

        $this->app = $app;

        return $app;
    }
}

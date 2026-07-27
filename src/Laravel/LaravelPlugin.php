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
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\RegisterProviders;

/**
 * Boots one Laravel application lazily for a test and resolves bound services.
 * #[Service] selects an explicit binding ID. Tests MUST isolate external
 * resources by GREENLIGHT_CHANNEL.
 */
final class LaravelPlugin implements HarnessProvider, ServiceResolver, TestLifecycleSubscriber
{
    /**
     * @var \Closure(): mixed
     */
    private readonly \Closure $factory;

    /** The active application belongs to the current test attempt. */
    private ?Application $app = null;

    private ?LaravelProcessState $processState = null;

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

        $this->releaseApplication();

        return $result;
    }

    /** An invalid application fails before Greenlight supplies a container service. */
    private function application(): Application
    {
        if ($this->app instanceof Application) {
            return $this->app;
        }

        LaravelFrameworkRequirement::check();
        $this->processState = LaravelProcessState::setEnvironment($this->env);

        try {
            $app = ($this->factory)();

            if (!$app instanceof Application) {
                throw LaravelBridgeError::notAnApplication(\get_debug_type($app));
            }

            $this->app = $app;

            if (!$app->bound(Kernel::class)) {
                throw LaravelBridgeError::consoleKernelUnavailable();
            }

            $kernel = $this->containerEntry($app, Kernel::class);

            if (!$kernel instanceof Kernel) {
                throw LaravelBridgeError::consoleKernelTypeMismatch(\get_debug_type($kernel));
            }

            $this->preserveDiagnosticHandlers($app);
            $kernel->bootstrap();

            return $app;
        } catch (\Throwable $threw) {
            $this->releaseApplication();

            throw $threw;
        } finally {
            RegisterProviders::flushState();
        }
    }

    /**
     * Laravel's bootstrapper installs process-global diagnostic handlers and a
     * shutdown callback. Greenlight owns diagnostics for the worker lifetime.
     */
    private function preserveDiagnosticHandlers(Application $app): void
    {
        $app->extend(
            HandleExceptions::class,
            static fn(): HandleExceptions => new class extends HandleExceptions {
                #[\Override]
                public function bootstrap(Application $app): void {}
            },
        );
    }

    private function containerEntry(Application $app, string $id): mixed
    {
        return $app->get($id);
    }

    private function releaseApplication(): void
    {
        $app = $this->app;
        $state = $this->processState;
        $this->app = null;
        $this->processState = null;

        try {
            $app?->flush();
        } finally {
            try {
                LaravelStateResetter::reset();
            } finally {
                $state?->restore();
            }
        }
    }
}

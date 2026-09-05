<?php

declare(strict_types=1);

namespace Greenlight\Laravel;

use Greenlight\Harness\Scope;
use Greenlight\Harness\Service;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Harness\ServiceSource;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Plugin\AfterTestSubscriber;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\TestContext;
use Greenlight\Result\TestResult;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Foundation\Bootstrap\RegisterProviders;

/**
 * Boots a Laravel application on first use and resolves bound services.
 * By default, Greenlight releases the application after each test attempt.
 *
 * `#[Service]` selects a binding ID or a named source. Isolate external test resources
 * by `GREENLIGHT_CHANNEL`.
 */
final class LaravelPlugin implements AfterTestSubscriber, HarnessProvider, ServiceResolver, ServiceSource
{
    /** @var non-empty-string|null */
    private readonly ?string $source;

    /** @var \Closure(): Application */
    private readonly \Closure $factory;

    /** The active application belongs to the current test attempt. */
    private ?Application $app = null;

    private ?LaravelProcessState $processState = null;

    /**
     * @param string|\Closure(): Application $application
     *   A path to a file that returns the application, usually bootstrap/app.php.
     *   For other application setup, pass a closure that returns the application.
     * @param non-empty-string $env
     * @param bool $refreshBetweenTests
     *   Set to false only when no service keeps state between tests.
     *   Tests on one worker then share one application without resets.
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string|\Closure $application,
        private readonly string $env = 'testing',
        private readonly bool $refreshBetweenTests = true,
        ?string $source = null,
    ) {
        if ($source === '') {
            throw new \InvalidArgumentException('Service source must not be empty.');
        }

        $this->source = $source;
        $this->factory = $application instanceof \Closure
            ? $application
            : static function () use ($application): Application {
                $applicationExists = ErrorTrap::run(static fn() => \is_file($application));

                if (!$applicationExists) {
                    throw LaravelBridgeError::bootstrapFileMissing($application);
                }

                $app = require $application;

                if (!$app instanceof Application) {
                    throw LaravelBridgeError::notAnApplication($app);
                }

                return $app;
            };
    }

    #[\Override]
    public function source(): ?string
    {
        return $this->source;
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
                $this->refreshBetweenTests ? Scope::PerTest : Scope::PerWorker,
                $this->application(...),
            ),
        ];
    }

    /**
     * @param class-string $type
     * @param list<object> $attributes
     * @throws ServiceResolutionFailed
     */
    #[\Override]
    public function resolve(string $type, array $attributes): ?object
    {
        $id = $type;
        $explicit = false;

        foreach ($attributes as $attribute) {
            if ($attribute instanceof Service) {
                $id = $attribute->id ?? $type;
                $explicit = true;
            }
        }

        try {
            $app = $this->application();

            if (!$app->bound($id)) {
                if ($explicit) {
                    throw LaravelBridgeError::unknownServiceId($id, $type);
                }

                return null;
            }

            $service = $app->make($id);

            if (!$service instanceof $type) {
                throw LaravelBridgeError::serviceTypeMismatch($id, $type, $service);
            }

            return $service;
        } catch (ServiceResolutionFailed $error) {
            throw $error;
        } catch (\Throwable $cause) {
            throw LaravelBridgeError::serviceResolutionFailed($id, $type, $cause);
        }
    }

    #[\Override]
    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        if (!$this->refreshBetweenTests || !$this->app instanceof Application) {
            return $result;
        }

        $this->releaseApplication();

        return $result;
    }

    /**
     * An invalid application fails before Greenlight supplies a container service.
     * @throws ServiceResolutionFailed
     */
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
                throw LaravelBridgeError::notAnApplication($app);
            }

            $this->app = $app;

            if (!$app->bound(Kernel::class)) {
                throw LaravelBridgeError::consoleKernelUnavailable();
            }

            $kernel = $this->containerEntry($app, Kernel::class);

            if (!$kernel instanceof Kernel) {
                throw LaravelBridgeError::consoleKernelTypeMismatch($kernel);
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

<?php

declare(strict_types=1);

namespace Greenlight\Hyperf;

use Greenlight\Harness\Scope;
use Greenlight\Harness\Service;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Harness\ServiceSource;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\TestAttemptRunner;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Plugin\WorkerBootstrapSubscriber;
use Greenlight\Plugin\WorkerRuntimeRunner;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ApplicationInterface;
use Hyperf\Coordinator\Constants;
use Hyperf\Coordinator\CoordinatorManager;
use Hyperf\Coroutine\Coroutine as HyperfCoroutine;
use Hyperf\Di\ClassLoader;
use Psr\Container\ContainerInterface;
use Swoole\Coroutine as SwooleCoroutine;
use Swoole\Coroutine\Channel;
use Swoole\Runtime;
use Swoole\Timer;

use function Hyperf\Coroutine\run;

/**
 * Initializes the Hyperf class loader once in each worker. It creates a
 * coroutine context for each test attempt.
 *
 * The default worker container lifetime matches a long-running Hyperf worker.
 * `#[Service]` selects a container ID or a named source. Isolate external test
 * resources by `GREENLIGHT_CHANNEL`.
 *
 * Requires `hyperf/framework` and `hyperf/di` 3.2. It also requires Swoole 5
 * or later and the pcntl extension. It does not support Swow.
 */
final class HyperfPlugin implements HarnessProvider, ServiceResolver, ServiceSource, TestAttemptRunner, WorkerBootstrapSubscriber, WorkerRuntimeRunner
{
    /** @var non-empty-string|null */
    private readonly ?string $source;

    private readonly string $basePath;

    private readonly string $containerFile;

    private bool $bootstrapped = false;

    private ?ContainerInterface $workerContainer = null;

    private ?ContainerInterface $activeContainer = null;

    /** @var null|\WeakReference<ContainerInterface> */
    private ?\WeakReference $previousContainer = null;

    /**
     * @param null|\Closure(ContainerInterface): void $reset
     *   Resets project-owned request state after each test attempt. The
     *   callback runs inside the test coroutine.
     * @param null|\Closure(ContainerInterface): void $dispose
     *   Releases project-owned resources when the selected container lifetime
     *   ends. The callback runs inside a coroutine.
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $basePath,
        private readonly ContainerLifetime $containerLifetime = ContainerLifetime::Worker,
        private readonly ?\Closure $reset = null,
        private readonly ?\Closure $dispose = null,
        private readonly ?int $hookFlags = null,
        ?string $source = null,
    ) {
        if ($source === '') {
            throw new \InvalidArgumentException('Service source must not be empty.');
        }

        $this->source = $source;
        $this->basePath = \rtrim($basePath, '/');
        $this->containerFile = $this->basePath . '/config/container.php';
    }

    #[\Override]
    public function source(): ?string
    {
        return $this->source;
    }

    /** @return list<ServiceDefinition> */
    #[\Override]
    public function services(): array
    {
        return [
            new ServiceDefinition(ContainerInterface::class, Scope::PerTest, $this->container(...)),
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
            $container = $this->container();

            if (!$container->has($id)) {
                if ($explicit) {
                    throw HyperfBridgeError::unknownServiceId($id, $type);
                }

                return null;
            }

            $service = $container->get($id);

            if (!$service instanceof $type) {
                throw HyperfBridgeError::serviceTypeMismatch($id, $type, $service);
            }

            return $service;
        } catch (ServiceResolutionFailed $error) {
            throw $error;
        } catch (\Throwable $cause) {
            throw HyperfBridgeError::serviceResolutionFailed($id, $type, $cause);
        }
    }

    /** @throws ServiceResolutionFailed */
    #[\Override]
    public function onWorkerBootstrap(WorkerBootstrapContext $context): void
    {
        HyperfFrameworkRequirement::check();
        [$basePath, $containerFileExists] = ErrorTrap::run(function () {
            $basePath = \realpath($this->basePath);

            if ($basePath === false || !\is_dir($basePath)) {
                return [false, false];
            }

            return [$basePath, \is_file($this->containerFile)];
        });

        if ($basePath === false) {
            throw HyperfBridgeError::basePathMissing($this->basePath);
        }

        if (!$containerFileExists) {
            throw HyperfBridgeError::containerFileMissing($this->containerFile);
        }

        if (\defined('BASE_PATH')) {
            $defined = \constant('BASE_PATH');

            if (!\is_string($defined)) {
                throw HyperfBridgeError::basePathConflict($basePath, $defined);
            }

            $definedPath = ErrorTrap::run(static fn() => \realpath($defined));

            if ($definedPath === false || $definedPath !== $basePath) {
                throw HyperfBridgeError::basePathConflict($basePath, $defined);
            }
        } else {
            \define('BASE_PATH', $basePath);
        }

        $this->initializeClassLoader($basePath);
        $this->bootstrapped = true;

        if ($this->containerLifetime === ContainerLifetime::Worker) {
            $container = $this->createContainer();
            $this->workerContainer = $container;
            ApplicationContext::setContainer($container);
            $this->bootApplication($container);
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $worker
     *
     * @return T
     * @throws ServiceResolutionFailed
     */
    #[\Override]
    public function runWorker(\Closure $worker): mixed
    {
        if ($this->containerLifetime === ContainerLifetime::TestAttempt) {
            return $worker();
        }

        if (!$this->bootstrapped || !$this->workerContainer instanceof ContainerInterface) {
            throw HyperfBridgeError::workerContainerUnavailable();
        }

        /** @var array{value: T}|array{} $result */
        $result = [];
        $failure = null;
        $completed = false;
        $flags = $this->hookFlags ?? SWOOLE_HOOK_ALL;

        try {
            $started = run(function () use ($worker, &$result, &$failure, &$completed): void {
                try {
                    $result = ['value' => $worker()];
                    $completed = true;
                } catch (\Throwable $threw) {
                    $failure = $threw;
                } finally {
                    $this->captureCleanup($failure, $this->disposeWorkerContainer(...));
                    $this->captureRuntimeCleanup($failure);
                }
            }, $flags);
        } finally {
            Runtime::enableCoroutine(0);
        }

        return $this->coroutineResult($started, $completed, $result, $failure);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $attempt
     *
     * @return T
     * @throws ServiceResolutionFailed
     */
    #[\Override]
    public function runTestAttempt(\Closure $attempt): mixed
    {
        if (!$this->bootstrapped) {
            throw HyperfBridgeError::containerOutsideAttempt();
        }

        if ($this->containerLifetime === ContainerLifetime::Worker) {
            return $this->runWorkerAttempt($attempt);
        }

        return $this->runIsolatedAttempt($attempt);
    }

    /** @throws ServiceResolutionFailed */
    private function initializeClassLoader(string $basePath): void
    {
        $runtimeDirectory = $basePath . '/runtime/container';

        if (!\is_dir($runtimeDirectory)
            && !ErrorTrap::run(static fn() => \mkdir($runtimeDirectory, 0o755, true), $warning)
        ) {
            throw HyperfBridgeError::scanLockUnavailable($runtimeDirectory . '/greenlight.scan.lock');
        }

        $lockPath = $runtimeDirectory . '/greenlight.scan.lock';
        $lock = ErrorTrap::run(static fn() => \fopen($lockPath, 'c'), $warning);

        if (!\is_resource($lock)) {
            throw HyperfBridgeError::scanLockUnavailable($lockPath);
        }

        try {
            $locked = ErrorTrap::run(static fn() => \flock($lock, \LOCK_EX), $warning);

            if (!$locked) {
                throw HyperfBridgeError::scanLockFailed($lockPath);
            }

            ClassLoader::init();
        } finally {
            ErrorTrap::run(static fn() => \flock($lock, \LOCK_UN), $warning);
            \fclose($lock);
        }
    }

    /**
     * @template T
     *
     * @param \Closure(): T $attempt
     *
     * @return T
     * @throws ServiceResolutionFailed
     */
    private function runWorkerAttempt(\Closure $attempt): mixed
    {
        if (!HyperfCoroutine::inCoroutine()) {
            throw HyperfBridgeError::workerRuntimeUnavailable();
        }

        $container = $this->workerContainer
            ?? throw HyperfBridgeError::workerContainerUnavailable();
        /** @var array{value: T}|array{} $result */
        $result = [];
        $failure = null;
        $completed = false;
        $finished = new Channel(1);
        $coroutineId = SwooleCoroutine::create(function () use (
            $attempt,
            $container,
            $finished,
            &$result,
            &$failure,
            &$completed,
        ): void {
            try {
                $this->activeContainer = $container;
                $result = ['value' => $attempt()];
                $completed = true;
            } catch (\Throwable $threw) {
                $failure = $threw;
            } finally {
                $this->captureCleanup($failure, function () use ($container): void {
                    $this->resetContainer($container);
                });
                $this->activeContainer = null;
                $finished->push(true);
            }
        });

        if ($coroutineId === false) {
            $finished->close();

            throw HyperfBridgeError::coroutineDidNotStart();
        }

        $didFinish = $finished->pop();
        $finished->close();

        return $this->coroutineResult($didFinish === true, $completed, $result, $failure);
    }

    /**
     * @template T
     *
     * @param \Closure(): T $attempt
     *
     * @return T
     * @throws ServiceResolutionFailed
     */
    private function runIsolatedAttempt(\Closure $attempt): mixed
    {
        /** @var array{value: T}|array{} $result */
        $result = [];
        $failure = null;
        $completed = false;
        $flags = $this->hookFlags ?? SWOOLE_HOOK_ALL;

        try {
            $started = run(function () use ($attempt, &$result, &$failure, &$completed): void {
                try {
                    $container = $this->createContainer();
                    $this->rejectReusedContainer($container);
                    $this->activeContainer = $container;
                    ApplicationContext::setContainer($container);
                    $this->bootApplication($container);
                    $result = ['value' => $attempt()];
                    $completed = true;
                } catch (\Throwable $threw) {
                    $failure = $threw;
                } finally {
                    $this->captureCleanup($failure, $this->disposeAttemptContainer(...));
                    $this->captureRuntimeCleanup($failure);
                }
            }, $flags);
        } finally {
            Runtime::enableCoroutine(0);
        }

        return $this->coroutineResult($started, $completed, $result, $failure);
    }

    /** @throws ServiceResolutionFailed */
    private function createContainer(): ContainerInterface
    {
        $loader = static fn(string $file): mixed => require $file;
        $container = $loader($this->containerFile);

        if (!$container instanceof ContainerInterface) {
            throw HyperfBridgeError::notAContainer($this->containerFile, $container);
        }

        return $container;
    }

    /** @throws ServiceResolutionFailed */
    private function rejectReusedContainer(ContainerInterface $container): void
    {
        if ($this->previousContainer?->get() === $container) {
            throw HyperfBridgeError::reusedContainer();
        }

        $this->previousContainer = \WeakReference::create($container);
    }

    /** @throws ServiceResolutionFailed */
    private function bootApplication(ContainerInterface $container): void
    {
        $application = $container->get(ApplicationInterface::class);

        if (!\is_object($application)) {
            throw HyperfBridgeError::applicationUnavailable($application);
        }
    }

    private function resetContainer(ContainerInterface $container): void
    {
        if ($this->reset instanceof \Closure) {
            ($this->reset)($container);
        }
    }

    private function disposeAttemptContainer(): void
    {
        $container = $this->activeContainer;
        $this->activeContainer = null;

        try {
            if ($container instanceof ContainerInterface) {
                $failure = null;
                $this->captureCleanup($failure, fn() => $this->resetContainer($container));

                if ($this->dispose instanceof \Closure) {
                    $this->captureCleanup($failure, fn() => ($this->dispose)($container));
                }

                if ($failure instanceof \Throwable) {
                    throw $failure;
                }
            }
        } finally {
            ApplicationContext::setContainer(new UnavailableContainer());
        }
    }

    private function disposeWorkerContainer(): void
    {
        $container = $this->workerContainer;
        $this->activeContainer = null;
        $this->workerContainer = null;

        try {
            if ($container instanceof ContainerInterface && $this->dispose instanceof \Closure) {
                ($this->dispose)($container);
            }
        } finally {
            ApplicationContext::setContainer(new UnavailableContainer());
        }
    }

    private function captureRuntimeCleanup(?\Throwable &$failure): void
    {
        $this->captureCleanup($failure, static function (): void {
            Timer::clearAll();
        });
        $this->captureCleanup(
            $failure,
            static function (): void {
                CoordinatorManager::until(Constants::WORKER_EXIT)->resume();
            },
        );
    }

    /** @param \Closure(): void $cleanup */
    private function captureCleanup(?\Throwable &$failure, \Closure $cleanup): void
    {
        try {
            $cleanup();
        } catch (\Throwable $threw) {
            $failure ??= $threw;
        }
    }

    /**
     * @template T
     *
     * @param array{value: T}|array{} $result
     *
     * @return T
     * @throws ServiceResolutionFailed
     */
    private function coroutineResult(bool $started, bool $completed, array $result, ?\Throwable $failure): mixed
    {
        if (!$started) {
            throw HyperfBridgeError::coroutineDidNotStart();
        }

        if ($failure instanceof \Throwable) {
            throw $failure;
        }

        if (!$completed || !\array_key_exists('value', $result)) {
            throw HyperfBridgeError::coroutineDidNotStart();
        }

        return $result['value'];
    }

    /** @throws ServiceResolutionFailed */
    private function container(): ContainerInterface
    {
        return $this->activeContainer ?? throw HyperfBridgeError::containerOutsideAttempt();
    }
}

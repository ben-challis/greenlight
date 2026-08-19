<?php

declare(strict_types=1);

namespace Greenlight\Tempest;

use Greenlight\Core\Result\TestResult;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\TestLifecycleSubscriber;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Plugin\WorkerBootstrapSubscriber;
use Tempest\Container\Container;
use Tempest\Container\GenericContainer;
use Tempest\Container\Tag;
use Tempest\Core\FrameworkKernel;
use Tempest\Core\Kernel;
use Tempest\Discovery\DiscoveryLocation;
use Tempest\Http\GenericRequest;
use Tempest\Http\Method;
use Tempest\Http\Request;

/**
 * Boots one Tempest long-running kernel for each worker. Tempest discovery,
 * configuration, container reset, deferred tasks, and shutdown events stay
 * under kernel control.
 *
 * The bridge uses the `testing` environment by default. Native `#[Tag]`
 * attributes select tagged Tempest bindings. Tests MUST isolate external
 * resources by `GREENLIGHT_CHANNEL`.
 */
final class TempestPlugin implements HarnessProvider, ServiceResolver, TestLifecycleSubscriber, WorkerBootstrapSubscriber
{
    private ?FrameworkKernel $kernel = null;

    private ?TempestProcessState $processState = null;

    private ?int $channel = null;

    /**
     * @param string $root The directory that contains the Tempest composer.json file.
     * @param list<DiscoveryLocation> $discoveryLocations Additional locations for Tempest discovery.
     * @throws \InvalidArgumentException
     */
    public function __construct(
        private readonly string $root,
        private readonly string $environment = 'testing',
        private readonly array $discoveryLocations = [],
    ) {
        if ($root === '') {
            throw new \InvalidArgumentException('Tempest application root MUST NOT be empty.');
        }

        if ($environment === '') {
            throw new \InvalidArgumentException('Tempest environment MUST NOT be empty.');
        }
    }

    #[\Override]
    public function onWorkerBootstrap(WorkerBootstrapContext $context): void
    {
        $this->channel = $context->channel->number;
    }

    /**
     * @return list<ServiceDefinition>
     */
    #[\Override]
    public function services(): array
    {
        return [
            new ServiceDefinition(Kernel::class, Scope::PerRun, $this->kernel(...)),
            new ServiceDefinition(Container::class, Scope::PerRun, $this->container(...)),
        ];
    }

    /**
     * @param class-string $type
     * @param list<object> $attributes
     * @throws TempestBridgeError
     */
    #[\Override]
    public function resolve(string $type, array $attributes): object
    {
        $tag = null;

        foreach ($attributes as $attribute) {
            if ($attribute instanceof Tag) {
                $tag = $attribute->name;
            }
        }

        try {
            $service = $this->container()->get($type, $tag);
        } catch (TempestBridgeError $error) {
            throw $error;
        } catch (\Throwable $cause) {
            throw TempestBridgeError::serviceResolutionFailed($type, $cause);
        }

        if (!$service instanceof $type) {
            throw TempestBridgeError::serviceTypeMismatch($type, \get_debug_type($service));
        }

        return $service;
    }

    #[\Override]
    public function beforeTest(TestContext $context): void
    {
        $kernel = $this->kernel;

        if ($kernel instanceof FrameworkKernel && $this->activate($kernel->container)) {
            $this->prepareBaseRequest($kernel->container);
        }
    }

    /**
     * @throws TempestBridgeError
     */
    #[\Override]
    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        if (!$this->kernel instanceof FrameworkKernel) {
            return $result;
        }

        try {
            $this->kernel->shutdown();
        } catch (\Throwable $cause) {
            $this->kernel = null;

            throw TempestBridgeError::shutdownFailed($this->root, $cause);
        } finally {
            $this->restoreProcessState();
        }

        return $result;
    }

    /**
     * @throws TempestBridgeError
     */
    private function kernel(): Kernel
    {
        $kernel = $this->kernel;

        if ($kernel instanceof FrameworkKernel) {
            if ($this->activate($kernel->container)) {
                $this->prepareBaseRequest($kernel->container);
            }

            return $kernel;
        }

        TempestFrameworkRequirement::check();
        $this->activate();

        try {
            $kernel = FrameworkKernel::boot(
                root: $this->root,
                discoveryLocations: $this->discoveryLocations,
                internalStorage: $this->internalStorage(),
                longRunning: true,
            );
            $this->prepareBaseRequest($kernel->container);
        } catch (\Throwable $cause) {
            $this->restoreProcessState();

            throw TempestBridgeError::bootFailed($this->root, $cause);
        }

        $this->kernel = $kernel;

        return $kernel;
    }

    /**
     * @throws TempestBridgeError
     */
    private function container(): Container
    {
        $kernel = $this->kernel();

        return $kernel->container;
    }

    private function activate(?Container $container = null): bool
    {
        if ($this->processState instanceof TempestProcessState) {
            return false;
        }

        $generic = $container instanceof GenericContainer ? $container : null;
        $this->processState = TempestProcessState::activate($this->environment, $generic);

        return true;
    }

    private function prepareBaseRequest(Container $container): void
    {
        $request = new GenericRequest(Method::GET, '/');
        $container->singleton(Request::class, $request);
        $container->singleton(GenericRequest::class, $request);
    }

    private function restoreProcessState(): void
    {
        $state = $this->processState;
        $this->processState = null;
        $state?->restore();
    }

    private function internalStorage(): string
    {
        $channel = $this->channel;

        if ($channel === null) {
            $rawChannel = \getenv('GREENLIGHT_CHANNEL');
            $channel = \is_string($rawChannel) && $rawChannel !== '' ? (int) $rawChannel : 0;
        }

        return \rtrim($this->root, '/\\') . '/.tempest/greenlight/' . \max(0, $channel);
    }
}

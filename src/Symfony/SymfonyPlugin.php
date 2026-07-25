<?php

declare(strict_types=1);

namespace Greenlight\Symfony;

use Greenlight\Core\Result\TestResult;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\TestContext;
use Greenlight\Plugin\TestLifecycleSubscriber;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The kernel boots lazily and lives for the worker lifetime.
 *
 * The container must expose Symfony's test container and, unless resets are
 * disabled, services_resetter. Disabling resets is unsafe when any service
 * carries state between tests.
 *
 * #[Service] overrides type-based lookup with an explicit id. External
 * resources must be isolated by GREENLIGHT_CHANNEL.
 */
final class SymfonyPlugin implements HarnessProvider, ServiceResolver, TestLifecycleSubscriber
{
    /**
     * @var \Closure(): KernelInterface
     */
    private readonly \Closure $factory;

    private ?KernelInterface $kernel = null;

    private ?ContainerInterface $testContainer = null;

    private ?ResetInterface $resetter = null;

    /**
     * @param string|\Closure(): KernelInterface $kernel
     *   A kernel class name to construct as new $kernel($env, $debug), or a
     *   closure returning the kernel when exotic construction is needed.
     * @param non-empty-string $env
     * @param bool $resetBetweenTests
     *   Set to false only when the container holds no stateful services;
     *   tests on one worker then share every service instance unreset.
     */
    public function __construct(
        string|\Closure $kernel,
        string $env = 'test',
        bool $debug = false,
        private readonly bool $resetBetweenTests = true,
    ) {
        $this->factory = $kernel instanceof \Closure
            ? $kernel
            : static function () use ($kernel, $env, $debug): KernelInterface {
                if (!\is_a($kernel, KernelInterface::class, true)) {
                    throw SymfonyBridgeError::notAKernel($kernel);
                }

                return new $kernel($env, $debug);
            };
    }

    /**
     * @return list<ServiceDefinition>
     */
    #[\Override]
    public function services(): array
    {
        return [
            new ServiceDefinition(KernelInterface::class, Scope::PerRun, $this->kernel(...)),
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

        $container = $this->container();

        if (!$container->has($id)) {
            if ($id !== $type) {
                throw SymfonyBridgeError::unknownServiceId($id, $type);
            }

            return null;
        }

        $service = $container->get($id);

        if (!$service instanceof $type) {
            throw SymfonyBridgeError::serviceTypeMismatch($id, $type, \get_debug_type($service));
        }

        return $service;
    }

    #[\Override]
    public function beforeTest(TestContext $context): void {}

    #[\Override]
    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        // Assigned only after a successful boot with resets enabled.
        $this->resetter?->reset();

        return $result;
    }

    /** An invalid kernel is not cached, so each use fails instead of running unisolated. */
    private function kernel(): KernelInterface
    {
        if ($this->kernel instanceof KernelInterface) {
            return $this->kernel;
        }

        $kernel = ($this->factory)();
        $kernel->boot();
        $container = $kernel->getContainer();

        $testContainer = $container->has('test.service_container')
            ? $container->get('test.service_container')
            : null;

        if (!$testContainer instanceof ContainerInterface) {
            throw SymfonyBridgeError::testContainerUnavailable($kernel->getEnvironment());
        }

        if ($this->resetBetweenTests) {
            $resetter = $container->has('services_resetter') ? $container->get('services_resetter') : null;

            if (!$resetter instanceof ResetInterface) {
                throw SymfonyBridgeError::resetterUnavailable($kernel->getEnvironment());
            }

            $this->resetter = $resetter;
        }

        $this->testContainer = $testContainer;
        $this->kernel = $kernel;

        return $kernel;
    }

    private function container(): ContainerInterface
    {
        $this->kernel();
        \assert($this->testContainer instanceof ContainerInterface);

        return $this->testContainer;
    }
}

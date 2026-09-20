<?php

declare(strict_types=1);

namespace Greenlight\Symfony;

use Greenlight\Harness\Scope;
use Greenlight\Harness\Service;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Harness\ServiceSource;
use Greenlight\Plugin\AfterTestSubscriber;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\TestContext;
use Greenlight\Result\TestResult;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Greenlight boots the kernel when it is first necessary. The kernel then
 * stays active for the worker lifetime.
 *
 * The container must expose Symfony's test container. If the configuration
 * does not disable resets, the container must expose `services_resetter`. If a
 * service keeps state between tests, do not disable resets.
 *
 * `#[Service]` selects a service ID or a named source. Isolate external
 * resources with `GREENLIGHT_CHANNEL`.
 */
final class SymfonyPlugin implements AfterTestSubscriber, HarnessProvider, ServiceResolver, ServiceSource
{
    /** @var non-empty-string|null */
    private readonly ?string $source;

    /**
     * @var \Closure(): KernelInterface
     */
    private readonly \Closure $factory;

    private ?KernelInterface $kernel = null;

    private ?ContainerInterface $testContainer = null;

    private ?ResetInterface $resetter = null;

    /**
     * @param class-string<KernelInterface>|\Closure(): KernelInterface $kernel
     *   A kernel class name that Greenlight constructs as
     *   new $kernel($env, $debug), or a closure that constructs the kernel.
     *   Use a closure for other constructor requirements.
     * @param non-empty-string $env
     * @param bool $resetBetweenTests
     *   For a container without stateful services, use false to disable
     *   resets. Tests on one worker then reuse the container without resets.
     *   Symfony service configuration determines which instances are shared.
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string|\Closure $kernel,
        string $env = 'test',
        bool $debug = false,
        private readonly bool $resetBetweenTests = true,
        ?string $source = null,
    ) {
        if ($source === '') {
            throw new \InvalidArgumentException('Service source must not be empty.');
        }

        $this->source = $source;
        $this->factory = $kernel instanceof \Closure
            ? $kernel
            : static function () use ($kernel, $env, $debug): KernelInterface {
                if (!\is_a($kernel, KernelInterface::class, true)) {
                    throw SymfonyBridgeError::notAKernel($kernel);
                }

                return new $kernel($env, $debug);
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
            new ServiceDefinition(KernelInterface::class, Scope::PerWorker, $this->kernel(...)),
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
                    throw SymfonyBridgeError::unknownServiceId($id, $type);
                }

                return null;
            }

            $service = $container->get($id);

            if (!$service instanceof $type) {
                throw SymfonyBridgeError::serviceTypeMismatch($id, $type, $service);
            }

            return $service;
        } catch (ServiceResolutionFailed $error) {
            throw $error;
        } catch (\Throwable $cause) {
            throw SymfonyBridgeError::serviceResolutionFailed($id, $type, $cause);
        }
    }

    #[\Override]
    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        // Greenlight assigns the resetter only after a successful boot when
        // the configuration enables resets.
        $this->resetter?->reset();

        return $result;
    }

    /**
     * Greenlight does not cache an invalid kernel. Thus, each use fails before
     * a test runs without isolation.
     * @throws ServiceResolutionFailed
     */
    private function kernel(): KernelInterface
    {
        if ($this->kernel instanceof KernelInterface) {
            return $this->kernel;
        }

        $kernel = ($this->factory)();

        if (!$kernel instanceof KernelInterface) {
            throw SymfonyBridgeError::notAKernelFromFactory($kernel);
        }

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

    /**
     * @throws ServiceResolutionFailed
     */
    private function container(): ContainerInterface
    {
        $this->kernel();
        \assert($this->testContainer instanceof ContainerInterface);

        return $this->testContainer;
    }
}

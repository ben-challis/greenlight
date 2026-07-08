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
 * Boots a Symfony kernel per worker and injects its services into tests.
 *
 * Register it in greenlight.php with the kernel class and environment, or
 * with a closure producing a ready kernel:
 *
 *     ->plugins(new SymfonyPlugin(App\Kernel::class, env: 'test', debug: false))
 *
 * The kernel boots lazily on first use and lives for the worker process
 * lifetime; KernelInterface itself is injectable as a per-run harness
 * service through services().
 *
 * The isolation strategy is validated once at boot, and a kernel that
 * cannot honour it is a configuration error, never a silent degradation:
 * the container must expose Symfony's test container (framework.test
 * enabled), and unless resets are explicitly waived it must expose
 * services_resetter. Passing resetBetweenTests: false waives the reset
 * requirement for containers with no stateful services; it is unsafe with
 * any service that carries state across tests.
 *
 * resolve() serves constructor parameters no harness service covers: the
 * declared type is looked up in the test container, and a #[Service]
 * attribute on the parameter overrides the lookup with an explicit id.
 *
 * afterTest() resets every kernel.reset tagged service through the
 * services_resetter captured at boot, so stateful services reset between
 * tests without rebooting the kernel. Tests on one worker share the kernel,
 * and isolation of external resources (databases, caches) belongs to the
 * container build, keyed off the GREENLIGHT_CHANNEL environment variable.
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
        // Set exactly when the kernel booted with the reset strategy; boot
        // validation guarantees it, so a null here means either no kernel
        // was used yet or resets were explicitly waived.
        $this->resetter?->reset();

        return $result;
    }

    /**
     * Boots the kernel on first use and validates the isolation strategy
     * before memoizing anything, so a kernel that cannot honour it fails
     * every test loudly instead of running unisolated.
     */
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

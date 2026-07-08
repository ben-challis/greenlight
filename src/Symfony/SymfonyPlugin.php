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
 * resolve() serves constructor parameters no harness service covers: the
 * declared type is looked up in the container, and a #[Service] attribute on
 * the parameter overrides the lookup with an explicit id. Private services
 * resolve through Symfony's test container, which requires the kernel to be
 * booted with framework.test enabled; without it, only public services are
 * reachable and explicit ids fail with a hint.
 *
 * afterTest() calls the container's services_resetter, so stateful services
 * reset between tests without rebooting the kernel. Tests on one worker
 * share the kernel, and isolation of external resources (databases, caches)
 * belongs to the container build, keyed off the GREENLIGHT_CHANNEL
 * environment variable.
 */
final class SymfonyPlugin implements HarnessProvider, ServiceResolver, TestLifecycleSubscriber
{
    /**
     * @var \Closure(): KernelInterface
     */
    private readonly \Closure $factory;

    private ?KernelInterface $kernel = null;

    /**
     * @param string|\Closure(): KernelInterface $kernel
     *   A kernel class name to construct as new $kernel($env, $debug), or a
     *   closure returning the kernel when exotic construction is needed.
     * @param non-empty-string $env
     */
    public function __construct(string|\Closure $kernel, string $env = 'test', bool $debug = false)
    {
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
                throw SymfonyBridgeError::unknownServiceId($id, $type, $this->hasTestContainer());
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
        $kernel = $this->kernel;

        if ($kernel instanceof KernelInterface) {
            $container = $kernel->getContainer();

            if ($container->has('services_resetter')) {
                $resetter = $container->get('services_resetter');

                if ($resetter instanceof ResetInterface) {
                    $resetter->reset();
                }
            }
        }

        return $result;
    }

    private function kernel(): KernelInterface
    {
        if (!$this->kernel instanceof KernelInterface) {
            $this->kernel = ($this->factory)();
            $this->kernel->boot();
        }

        return $this->kernel;
    }

    /**
     * The test container when framework.test exposed it, the compiled
     * container otherwise.
     */
    private function container(): ContainerInterface
    {
        $container = $this->kernel()->getContainer();

        if ($container->has('test.service_container')) {
            $testContainer = $container->get('test.service_container');

            if ($testContainer instanceof ContainerInterface) {
                return $testContainer;
            }
        }

        return $container;
    }

    private function hasTestContainer(): bool
    {
        return $this->kernel()->getContainer()->has('test.service_container');
    }
}

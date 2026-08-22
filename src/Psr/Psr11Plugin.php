<?php

declare(strict_types=1);

namespace Greenlight\Psr;

use Greenlight\Core\Result\TestResult;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Harness\ServiceResolution;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Plugin\AfterTestSubscriber;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\TestContext;
use Psr\Container\ContainerInterface;

/**
 * Creates a PSR-11 container lazily and resolves its services. By default, the
 * plugin discards the container after each test that uses it.
 *
 * `#[Service]` selects an explicit ID. Isolate external test resources by
 * `GREENLIGHT_CHANNEL`.
 */
final class Psr11Plugin implements AfterTestSubscriber, HarnessProvider, ServiceResolver
{
    private ?ContainerInterface $activeContainer = null;

    /**
     * @param \Closure():ContainerInterface $factory
     *   A factory that returns the application container.
     * @param bool $refreshBetweenTests
     *   Set to false only when the reset callback removes all container state,
     *   or when services do not keep state.
     * @param (\Closure(ContainerInterface): void)|null $reset
     *   An optional callback that resets the active container after each test.
     */
    public function __construct(
        private readonly \Closure $factory,
        private readonly bool $refreshBetweenTests = true,
        private readonly ?\Closure $reset = null,
    ) {}

    /**
     * @return list<ServiceDefinition>
     */
    #[\Override]
    public function services(): array
    {
        return [
            new ServiceDefinition(
                ContainerInterface::class,
                $this->refreshBetweenTests ? Scope::PerTest : Scope::PerWorker,
                $this->container(...),
            ),
        ];
    }

    /**
     * @throws ServiceResolutionFailed
     */
    private function container(): ContainerInterface
    {
        if ($this->activeContainer instanceof ContainerInterface) {
            return $this->activeContainer;
        }

        try {
            $container = $this->createContainer();
        } catch (\Throwable $threw) {
            throw Psr11BridgeError::factoryFailed($threw);
        }

        if (!$container instanceof ContainerInterface) {
            throw Psr11BridgeError::notAContainer(\get_debug_type($container));
        }

        $this->activeContainer = $container;

        return $container;
    }

    private function createContainer(): mixed
    {
        return ($this->factory)();
    }

    /**
     * @param class-string $type
     * @param list<object> $attributes
     */
    #[\Override]
    public function resolve(string $type, array $attributes): ServiceResolution
    {
        $id = $type;
        $explicit = false;

        foreach ($attributes as $attribute) {
            if ($attribute instanceof Service) {
                $id = $attribute->id;
                $explicit = true;
            }
        }

        try {
            $container = $this->container();

            $hasService = $container->has($id);
        } catch (ServiceResolutionFailed $error) {
            return ServiceResolution::failed($error);
        } catch (\Throwable $threw) {
            return ServiceResolution::failed(Psr11BridgeError::serviceCheckFailed($id, $threw));
        }

        if (!$hasService) {
            return $explicit
                ? ServiceResolution::failed(Psr11BridgeError::unknownServiceId($id, $type))
                : ServiceResolution::unhandled();
        }

        try {
            $service = $container->get($id);
        } catch (\Throwable $threw) {
            return ServiceResolution::failed(Psr11BridgeError::serviceReadFailed($id, $threw));
        }

        if (!$service instanceof $type) {
            return ServiceResolution::failed(
                Psr11BridgeError::serviceTypeMismatch($id, $type, \get_debug_type($service)),
            );
        }

        return ServiceResolution::resolved($service);
    }

    /**
     * @throws ServiceResolutionFailed
     */
    #[\Override]
    public function afterTest(TestContext $context, TestResult $result): TestResult
    {
        $container = $this->activeContainer;

        if (!$container instanceof ContainerInterface) {
            return $result;
        }

        if ($this->refreshBetweenTests) {
            $this->activeContainer = null;
        }

        if (!$this->reset instanceof \Closure) {
            return $result;
        }

        try {
            ($this->reset)($container);
        } catch (\Throwable $threw) {
            $this->activeContainer = null;

            throw Psr11BridgeError::resetFailed($threw);
        }

        return $result;
    }
}

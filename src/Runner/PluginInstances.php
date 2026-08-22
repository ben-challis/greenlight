<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Expect\ExpectationExtension;
use Greenlight\Harness\ServiceResolver;
use Greenlight\Plugin\AfterTestSubscriber;
use Greenlight\Plugin\BeforeTestSubscriber;
use Greenlight\Plugin\HarnessProvider;
use Greenlight\Plugin\IntegrationFixtureProvider;
use Greenlight\Plugin\Plugin;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Plugin\PluginRegistry;
use Greenlight\Plugin\RetryDecider;
use Greenlight\Plugin\RunLifecycleSubscriber;
use Greenlight\Plugin\TestAttemptRunner;
use Greenlight\Plugin\WorkerBootstrapSubscriber;
use Greenlight\Plugin\WorkerRuntimeRunner;

/**
 * Creates the plugin instances that one execution side owns.
 *
 * @internal
 */
final readonly class PluginInstances
{
    /**
     * @var list<class-string>
     */
    private const array ORCHESTRATOR_CAPABILITIES = [
        IntegrationFixtureProvider::class,
        RunLifecycleSubscriber::class,
    ];

    /**
     * @var list<class-string>
     */
    private const array WORKER_CAPABILITIES = [
        AfterTestSubscriber::class,
        BeforeTestSubscriber::class,
        ExpectationExtension::class,
        HarnessProvider::class,
        RetryDecider::class,
        ServiceResolver::class,
        TestAttemptRunner::class,
        WorkerBootstrapSubscriber::class,
        WorkerRuntimeRunner::class,
    ];

    /**
     * @param list<PluginDefinition> $definitions
     */
    public static function forWorker(array $definitions): PluginRegistry
    {
        return PluginRegistry::forWorker(self::createSupporting($definitions, self::WORKER_CAPABILITIES));
    }

    /**
     * @param list<PluginDefinition> $definitions
     */
    public static function forOrchestrator(array $definitions): PluginRegistry
    {
        return PluginRegistry::orchestratorSide(self::createSupporting($definitions, self::ORCHESTRATOR_CAPABILITIES));
    }

    /**
     * @param list<PluginDefinition> $definitions
     */
    public static function hasWorkerBootstrapSubscribers(array $definitions): bool
    {
        return \array_any(
            $definitions,
            static fn(PluginDefinition $definition): bool => $definition->supports(WorkerBootstrapSubscriber::class),
        );
    }

    /**
     * @param list<PluginDefinition> $definitions
     * @param list<class-string> $capabilities
     *
     * @return list<Plugin>
     */
    private static function createSupporting(array $definitions, array $capabilities): array
    {
        $plugins = [];

        foreach ($definitions as $definition) {
            foreach ($capabilities as $capability) {
                if ($definition->supports($capability)) {
                    $plugins[] = $definition->create();

                    break;
                }
            }
        }

        return $plugins;
    }
}

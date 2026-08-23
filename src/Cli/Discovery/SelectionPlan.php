<?php

declare(strict_types=1);

namespace Greenlight\Cli\Discovery;

use Greenlight\Cli\Configuration\LoadedConfiguration;
use Greenlight\Cli\Input\CliError;
use Greenlight\Cli\State\RunState;
use Greenlight\Config\StorageLayout;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\Plan\PlanOrder;

/**
 * Makes the selected plan that listing interfaces expose.
 *
 * @internal
 */
final class SelectionPlan
{
    private function __construct() {}

    /**
     * @throws CliError
     * @throws DiscoveryError
     */
    public static function resolve(
        LoadedConfiguration $configuration,
        string $workingDirectory,
        bool $failed,
    ): ExecutionPlan {
        $resolved = $configuration->resolved;
        $storage = StorageLayout::resolve(
            $resolved->storage,
            $workingDirectory,
            $resolved->suiteSelection->stateIdentity(),
        );
        $state = RunState::forFile($storage->runStateFile);
        $previousFailures = $state->failedTests();

        if ($failed && $previousFailures === null) {
            throw CliError::failedRequiresState();
        }

        if ($failed && $previousFailures === []) {
            return new ExecutionPlan([], $resolved->order->seed);
        }

        $selection = $failed && \is_array($previousFailures)
            ? $resolved->selection->withExactIds($previousFailures)
            : $resolved->selection;
        $priorityClasses = [];

        if (!$resolved->order->isRandomized() && \is_array($previousFailures)) {
            foreach ($previousFailures as $id) {
                $class = \strstr($id, '::', true);

                if (\is_string($class) && $class !== '' && !\in_array($class, $priorityClasses, true)) {
                    $priorityClasses[] = $class;
                }
            }
        }

        $classSeconds = $resolved->order->isRandomized() ? [] : $state->classSeconds();
        $plan = new SelectionDiscovery($configuration, $workingDirectory)->plan($selection);

        return PlanOrder::schedule($plan, $priorityClasses, $classSeconds);
    }
}

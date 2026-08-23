<?php

declare(strict_types=1);

namespace Greenlight\Config;

use Greenlight\Test\TestSelection;

/**
 * Contains the settings for one resolved command.
 *
 * @internal
 */
final readonly class ResolvedConfiguration
{
    public function __construct(
        public DiscoveryConfiguration $discovery,
        public SuiteSelection $suiteSelection,
        public WorkerConfiguration $workers,
        public ExecutionConfiguration $execution,
        public RunOrder $order,
        public TestSelection $selection,
        public ?CoverageConfiguration $coverage,
        public WatchConfiguration $watch,
        public StorageConfiguration $storage,
    ) {}
}

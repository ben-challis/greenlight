<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Core\Test\SchedulingPolicy;
use Greenlight\Core\Test\TestDefinition;
use Greenlight\Discovery\PlanEntry;

/** Creates a plan entry from one test definition and optional data-set key. */
final class PlanEntryFixture
{
    private function __construct() {}

    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     * @param non-empty-string|null $dataSetKey
     * @param list<non-empty-string> $resources
     */
    public static function create(
        string $class,
        string $method = 'runs',
        ?string $dataSetKey = null,
        array $resources = [],
        bool $isolated = false,
        bool $allowParallel = false,
    ): PlanEntry {
        return new PlanEntry(
            new TestDefinition(
                $class,
                $method,
                scheduling: new SchedulingPolicy($isolated, $resources, $allowParallel),
            ),
            $dataSetKey,
        );
    }
}

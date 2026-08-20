<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\PlanEntry;

/** Creates a plan entry with matching identifier and metadata fields. */
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
    ): PlanEntry {
        return new PlanEntry(
            new TestId($class, $method, $dataSetKey),
            new TestMetadata($class, $method, isolated: $isolated, resources: $resources),
        );
    }
}

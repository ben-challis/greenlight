<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Config\Configuration;
use Greenlight\Discovery\Filter;

/**
 * The single mapping from a resolved Configuration to a discovery Filter.
 *
 * Both runners and the CLI listing path derive their Filter here, so the
 * selection a listing prints is the selection a run executes.
 *
 * @internal
 */
final class SelectionFilter
{
    #[CoverageIgnore]
    private function __construct() {}

    public static function fromConfiguration(Configuration $configuration): Filter
    {
        return new Filter(
            includeGroups: $configuration->groups,
            excludeGroups: $configuration->excludeGroups,
            excludeClasses: $configuration->excludeClasses,
            excludeMethods: $configuration->excludeMethods,
            excludePaths: $configuration->excludePaths,
            includeIds: $configuration->filters,
            includeExactIds: $configuration->onlyTests ?? [],
        );
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Config\Configuration;
use Greenlight\Discovery\Filter;

/**
 * Makes the discovery Filter from a resolved Configuration.
 *
 * Both runners and the CLI list command use this method. Thus, a list command
 * and a run use the same selection.
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

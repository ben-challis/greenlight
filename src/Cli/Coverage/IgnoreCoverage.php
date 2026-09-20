<?php

declare(strict_types=1);

namespace Greenlight\Cli\Coverage;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Ignore\IgnoreFilter;
use Greenlight\Plugin\CoverageMapTransformer;
use Greenlight\Plugin\Prioritized;

/**
 * Removes source lines that have a coverage-ignore marker.
 *
 * @internal
 */
final readonly class IgnoreCoverage implements CoverageMapTransformer, Prioritized
{
    #[\Override]
    public function priority(): int
    {
        return \PHP_INT_MIN;
    }

    #[\Override]
    public function transformCoverageMap(CoverageMap $coverage): CoverageMap
    {
        return new IgnoreFilter()->apply($coverage);
    }
}

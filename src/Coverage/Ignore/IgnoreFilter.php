<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Ignore;

use Greenlight\Coverage\CoverageMap;

/**
 * Removes ignored lines from a merged coverage map.
 *
 * apply() examines each file one time. It removes ignored lines from the
 * covered and uncovered sets. Thus, ignored code does not change totals.
 * The method removes files with no executable lines. Raw coverage conversion
 * also removes these files.
 *
 * @internal
 */
final readonly class IgnoreFilter
{
    public function __construct(private IgnoreScanner $scanner = new IgnoreScanner()) {}

    public function apply(CoverageMap $map): CoverageMap
    {
        $files = [];

        foreach ($map->files() as $file) {
            $ignored = $this->scanner->ignoredLines($file->file);

            if ($ignored === []) {
                $files[] = $file;

                continue;
            }

            $filtered = $file->withoutLines($ignored);

            if ($filtered->coveredLines === [] && $filtered->uncoveredLines === [] && $filtered->functions === []) {
                continue;
            }

            $files[] = $filtered;
        }

        return new CoverageMap($files, $map->branchCoverage);
    }
}

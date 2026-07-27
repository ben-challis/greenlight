<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Ignore;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;

/**
 * Removes ignored lines from a merged coverage map.
 *
 * apply() examines each file one time. It removes ignored lines from the
 * covered and uncovered sets. Thus, ignored code does not change totals.
 * The method removes files with no executable lines. CoverageMap::fromRaw()
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

            $covered = \array_values(\array_filter($file->coveredLines, static fn(int $line): bool => !isset($ignored[$line])));
            $uncovered = \array_values(\array_filter($file->uncoveredLines, static fn(int $line): bool => !isset($ignored[$line])));

            if ($covered === [] && $uncovered === []) {
                continue;
            }

            $files[] = new FileCoverage($file->file, $covered, $uncovered);
        }

        return new CoverageMap($files);
    }
}

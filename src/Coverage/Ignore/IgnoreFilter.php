<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Ignore;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;

/**
 * Removes ignored lines from a merged coverage map.
 *
 * apply() scans each file once and subtracts its ignored lines from both
 * the covered and uncovered sets, so ignored code affects no total whether
 * it ran or not. Files left with no executable lines are dropped, mirroring
 * how CoverageMap::fromRaw() skips files without executable lines.
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

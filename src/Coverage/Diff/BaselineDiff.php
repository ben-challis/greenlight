<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Diff;

use Greenlight\Coverage\CoverageMap;

/**
 * Compares a baseline coverage map with a current coverage map.
 *
 * between() compares the coverage percentage and the uncovered-line count of
 * each file. It does not compare line numbers, because an edit can move
 * lines.
 *
 * @internal
 */
final readonly class BaselineDiff
{
    public static function between(CoverageMap $baseline, CoverageMap $current): BaselineDiffReport
    {
        $baselineFiles = $baseline->files();
        $currentFiles = $current->files();

        $paths = \array_keys($baselineFiles + $currentFiles);
        \sort($paths, \SORT_STRING);

        $deltas = [];

        foreach ($paths as $path) {
            $before = $baselineFiles[$path] ?? null;
            $after = $currentFiles[$path] ?? null;

            $delta = new FileDelta(
                $path,
                $before?->percentage(),
                $after?->percentage(),
                $before === null ? null : \count($before->uncoveredLines),
                $after === null ? null : \count($after->uncoveredLines),
            );

            if ($delta->isShared() && $delta->delta() === 0.0 && !$delta->uncoveredLinesChanged()) {
                continue;
            }

            $deltas[$path] = $delta;
        }

        return new BaselineDiffReport($deltas, $baseline->totalPercentage(), $current->totalPercentage());
    }
}

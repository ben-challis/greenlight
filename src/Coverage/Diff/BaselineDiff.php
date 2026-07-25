<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Diff;

use Greenlight\Coverage\CoverageMap;

/** @internal */
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

            $newlyUncovered = [];

            if ($after !== null) {
                $previouslyUncovered = $before === null ? [] : \array_fill_keys($before->uncoveredLines, true);

                foreach ($after->uncoveredLines as $line) {
                    if (!isset($previouslyUncovered[$line])) {
                        $newlyUncovered[] = $line;
                    }
                }
            }

            $delta = new FileDelta(
                $path,
                $before?->percentage(),
                $after?->percentage(),
                $newlyUncovered,
            );

            if ($delta->delta() === 0.0 && $newlyUncovered === [] && ($before === null) === ($after === null)) {
                continue;
            }

            $deltas[$path] = $delta;
        }

        return new BaselineDiffReport(
            $deltas,
            $baseline->totalPercentage(),
            $current->totalPercentage(),
        );
    }
}

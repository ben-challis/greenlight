<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;

/**
 * Contains a normalized map of line numbers to driver status values for each file.
 * A positive value means that the line executed. Minus one means uncovered.
 * Minus two means dead code.
 *
 * CoverageMap conversion removes dead code.
 *
 * @internal
 */
final readonly class RawCoverage
{
    /**
     * @var array<string, array<int, int>>
     */
    public array $lines;

    /**
     * @param array<mixed> $lines Raw extension output.
     */
    public function __construct(array $lines)
    {
        $normalized = [];

        foreach ($lines as $path => $fileLines) {
            if (!\is_string($path) || !\is_array($fileLines)) {
                continue;
            }

            $statuses = [];

            foreach ($fileLines as $line => $status) {
                if (\is_int($line) && \is_int($status)) {
                    $statuses[$line] = $status;
                }
            }

            $normalized[$path] = $statuses;
        }

        $this->lines = $normalized;
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * Converts raw driver output to a coverage map. It removes dead code
     * lines and files that the path filter rejects. A positive status becomes
     * covered. A status of minus one becomes uncovered.
     */
    public function toMap(?PathFilter $filter = null): CoverageMap
    {
        $filter ??= PathFilter::all();
        $files = [];

        foreach ($this->lines as $path => $lines) {
            if ($path === '' || !$filter->accepts($path)) {
                continue;
            }

            $covered = [];
            $uncovered = [];

            foreach ($lines as $line => $status) {
                if ($line < 1) {
                    continue;
                }

                if ($status >= 1) {
                    $covered[] = $line;
                } elseif ($status === -1) {
                    $uncovered[] = $line;
                }
            }

            if ($covered === [] && $uncovered === []) {
                continue;
            }

            $files[] = new FileCoverage($path, $covered, $uncovered);
        }

        return new CoverageMap($files);
    }
}

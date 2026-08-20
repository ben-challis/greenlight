<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

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
}

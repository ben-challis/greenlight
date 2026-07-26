<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

/**
 * Contains a map of line numbers to driver status values for each file.
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
     * @param array<string, array<int, int>> $lines file path => line number => status flag
     */
    public function __construct(public array $lines) {}

    public static function none(): self
    {
        return new self([]);
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Coverage;

/**
 * Unnormalised driver output for one collection window.
 *
 * Per file, it holds a map of line number to status flag in the shared driver
 * vocabulary: a value of one or more means the line executed, minus one means
 * executable but not executed, minus two means dead code.
 *
 * Dead code is dropped during normalisation into a CoverageMap.
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

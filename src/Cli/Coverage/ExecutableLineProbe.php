<?php

declare(strict_types=1);

namespace Greenlight\Cli\Coverage;

use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;

/**
 * Finds the executable lines of source files that no test loaded.
 *
 * measure() returns each measured file with all executable lines uncovered.
 * It does not execute the files.
 *
 * @internal
 */
interface ExecutableLineProbe
{
    /**
     * @param list<non-empty-string> $files absolute paths
     *
     * @throws CoverageError when the probe cannot measure files
     */
    public function measure(array $files): CoverageMap;
}

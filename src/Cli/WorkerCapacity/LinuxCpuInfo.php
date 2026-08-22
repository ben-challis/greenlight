<?php

declare(strict_types=1);

namespace Greenlight\Cli\WorkerCapacity;

use Greenlight\Attribute\CoverageIgnore;

/**
 * Reads the logical processor count from a Linux CPU information document.
 *
 * @internal
 */
final class LinuxCpuInfo
{
    #[CoverageIgnore]
    private function __construct() {}

    /**
     * @return positive-int|null
     */
    public static function processorCount(string $cpuinfo): ?int
    {
        $count = \preg_match_all('/^processor\s*:/m', $cpuinfo);

        return $count > 0 ? $count : null;
    }
}

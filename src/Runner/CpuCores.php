<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Fidry\CpuCoreCounter\CpuCoreCounter;
use Fidry\CpuCoreCounter\NumberOfCpuCoreNotFound;

/**
 * Resolves the 'auto' worker count to the number of logical cores.
 *
 * When the consuming project has fidry/cpu-core-counter installed, count()
 * uses its detection because it understands cgroup limits and more platforms.
 * Otherwise a small built-in probe answers.
 *
 * When the platform gives no answer, a conservative default is used.
 *
 * @internal
 */
final class CpuCores
{
    private const int FALLBACK = 4;

    private function __construct() {}

    /**
     * @return positive-int
     */
    public static function count(): int
    {
        if (\class_exists(CpuCoreCounter::class)) {
            try {
                return new CpuCoreCounter()->getCount();
            } catch (NumberOfCpuCoreNotFound) {
            }
        }

        return self::probe();
    }

    /**
     * @return positive-int
     */
    private static function probe(): int
    {
        if (\is_file('/proc/cpuinfo')) {
            $cpuinfo = @\file_get_contents('/proc/cpuinfo');

            if (\is_string($cpuinfo)) {
                $count = \preg_match_all('/^processor\s*:/m', $cpuinfo);

                if ($count > 0) {
                    return $count;
                }
            }
        }

        if (\PHP_OS_FAMILY === 'Darwin' && \function_exists('shell_exec')) {
            $output = @\shell_exec('sysctl -n hw.logicalcpu 2>/dev/null');

            if (\is_string($output) && (int) \trim($output) > 0) {
                return \max(1, (int) \trim($output));
            }
        }

        return self::FALLBACK;
    }
}

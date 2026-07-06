<?php

declare(strict_types=1);

namespace Greenlight\Runner;

/**
 * Resolves the 'auto' worker count to the number of logical cores, falling
 * back to a conservative default when the platform gives no answer.
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

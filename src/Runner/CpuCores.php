<?php

declare(strict_types=1);

namespace Greenlight\Runner;

use Fidry\CpuCoreCounter\CpuCoreCounter;
use Fidry\CpuCoreCounter\NumberOfCpuCoreNotFound;
use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Core\DecimalInteger;
use Greenlight\Core\ErrorTrap;

/**
 * Converts the 'auto' worker count to the available CPU count.
 *
 * If the consumer has fidry/cpu-core-counter, count() uses it. That package
 * supports cgroup limits and more platforms. Otherwise, a small built-in
 * probe supplies the count.
 *
 * If the platform gives no count, the method uses a conservative default.
 *
 * @internal
 */
final class CpuCores
{
    private const int FALLBACK = 4;

    #[CoverageIgnore]
    private function __construct() {}

    /**
     * @return positive-int
     */
    public static function count(): int
    {
        if (\class_exists(CpuCoreCounter::class)) {
            try {
                return ErrorTrap::run(static fn(): int => new CpuCoreCounter()->getCount());
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
        if (ErrorTrap::run(static fn(): bool => \is_file('/proc/cpuinfo'))) {
            $cpuinfo = ErrorTrap::run(static fn(): string|false => \file_get_contents('/proc/cpuinfo'));

            if (\is_string($cpuinfo)) {
                $count = LinuxCpuInfo::processorCount($cpuinfo);

                if ($count !== null) {
                    return $count;
                }
            }
        }

        if (\PHP_OS_FAMILY === 'Darwin' && \function_exists('shell_exec')) {
            $output = ErrorTrap::run(static fn(): string|false|null => \shell_exec('sysctl -n hw.logicalcpu 2>/dev/null'));

            if (\is_string($output)) {
                $count = DecimalInteger::parse(\trim($output));

                if ($count !== null && $count > 0) {
                    return $count;
                }
            }
        }

        return self::FALLBACK;
    }
}

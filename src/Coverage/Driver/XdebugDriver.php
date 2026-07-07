<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Driver;

use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\RawCoverage;

/**
 * Line coverage via the Xdebug extension.
 *
 * Xdebug must be running with a mode that includes "coverage".
 *
 * Collection asks Xdebug for unused and dead code analysis so uncovered lines
 * (minus one) and dead code (minus two) are distinguishable downstream; dead
 * code is dropped during normalisation into a CoverageMap.
 *
 * @internal
 */
final class XdebugDriver implements CoverageDriver
{
    private bool $collecting = false;

    public function __construct()
    {
        if (!self::isAvailable()) {
            throw CoverageError::driverUnavailable('xdebug', 'Enable the xdebug extension and include "coverage" in xdebug.mode or the XDEBUG_MODE environment variable.');
        }
    }

    #[\Override]
    public static function isAvailable(): bool
    {
        return \extension_loaded('xdebug') && \in_array('coverage', self::activeModes(), true);
    }

    #[\Override]
    public function start(): void
    {
        if ($this->collecting) {
            throw new \LogicException('Xdebug collection window is already open; stop() must be called first.');
        }

        \xdebug_start_code_coverage(XDEBUG_CC_UNUSED | XDEBUG_CC_DEAD_CODE);
        $this->collecting = true;
    }

    #[\Override]
    public function stop(): RawCoverage
    {
        if (!$this->collecting) {
            throw new \LogicException('Xdebug collection window is not open; start() must be called first.');
        }

        $collected = \xdebug_get_code_coverage();
        \xdebug_stop_code_coverage();
        $this->collecting = false;

        return new RawCoverage($this->normalise($collected));
    }

    /**
     * @return list<string>
     */
    private static function activeModes(): array
    {
        if (\function_exists('xdebug_info')) {
            $modes = \xdebug_info('mode');

            if (\is_array($modes)) {
                $names = [];

                foreach ($modes as $mode) {
                    if (\is_string($mode)) {
                        $names[] = $mode;
                    }
                }

                return $names;
            }
        }

        $ini = \ini_get('xdebug.mode');

        if (!\is_string($ini) || $ini === '') {
            return [];
        }

        return \array_map(\trim(...), \explode(',', $ini));
    }

    /**
     * @param array<mixed> $collected
     *
     * @return array<string, array<int, int>>
     */
    private function normalise(array $collected): array
    {
        $lines = [];

        foreach ($collected as $path => $fileLines) {
            if (!\is_string($path) || !\is_array($fileLines)) {
                continue;
            }

            $statuses = array_filter($fileLines, fn($status, $line) => \is_int($line) && \is_int($status), ARRAY_FILTER_USE_BOTH);

            $lines[$path] = $statuses;
        }

        return $lines;
    }
}

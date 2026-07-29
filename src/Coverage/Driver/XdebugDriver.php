<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Driver;

use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\RawCoverage;

/**
 * Xdebug must operate in a mode that includes "coverage".
 *
 * Greenlight requests unused and dead code analysis from Xdebug. Xdebug marks
 * uncovered lines with minus one and dead code with minus two. CoverageMap
 * conversion removes dead code.
 *
 * @internal
 */
final class XdebugDriver implements CoverageDriver
{
    private bool $collecting = false;

    private readonly XdebugRuntime $runtime;

    public function __construct(?XdebugRuntime $runtime = null)
    {
        if (!$runtime instanceof XdebugRuntime && !self::isAvailable()) {
            throw CoverageError::driverUnavailable('xdebug', 'Enable the Xdebug extension. Add "coverage" to xdebug.mode or the XDEBUG_MODE environment variable.');
        }

        $this->runtime = $runtime ?? new NativeXdebugRuntime();
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
            throw new \LogicException('The Xdebug collection window is already open. Call stop() before start().');
        }

        $this->runtime->start(\XDEBUG_CC_UNUSED | \XDEBUG_CC_DEAD_CODE);
        $this->collecting = true;
    }

    #[\Override]
    public function stop(): RawCoverage
    {
        if (!$this->collecting) {
            throw new \LogicException('The Xdebug collection window is not open. Call start() before stop().');
        }

        try {
            $collected = $this->runtime->collect();
        } finally {
            try {
                $this->runtime->stop();
            } finally {
                $this->collecting = false;
            }
        }

        return new RawCoverage($this->normalize($collected));
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
    private function normalize(array $collected): array
    {
        $lines = [];

        foreach ($collected as $path => $fileLines) {
            if (!\is_string($path) || !\is_array($fileLines)) {
                continue;
            }

            $statuses = \array_filter($fileLines, fn($status, $line) => \is_int($line) && \is_int($status), \ARRAY_FILTER_USE_BOTH);

            $lines[$path] = $statuses;
        }

        return $lines;
    }
}

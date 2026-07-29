<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Driver;

use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\RawCoverage;

/**
 * pcov reports each observed line as covered (one) or uncovered (minus one).
 * It does not detect dead code.
 *
 * stop() clears the collected state. Thus, each collection period contains
 * only its own data.
 *
 * @internal
 */
final class PcovDriver implements CoverageDriver
{
    private bool $collecting = false;

    private readonly PcovRuntime $runtime;

    public function __construct(?PcovRuntime $runtime = null)
    {
        if (!$runtime instanceof PcovRuntime && !self::isAvailable()) {
            throw CoverageError::driverUnavailable('pcov', 'Install and enable the pcov extension.');
        }

        $this->runtime = $runtime ?? new NativePcovRuntime();
    }

    #[\Override]
    public static function isAvailable(): bool
    {
        return \extension_loaded('pcov');
    }

    #[\Override]
    public function start(): void
    {
        if ($this->collecting) {
            throw new \LogicException('The pcov collection window is already open. Call stop() before start().');
        }

        $this->runtime->start();
        $this->collecting = true;
    }

    #[\Override]
    public function stop(): RawCoverage
    {
        if (!$this->collecting) {
            throw new \LogicException('The pcov collection window is not open. Call start() before stop().');
        }

        try {
            $collected = $this->runtime->collect();
        } finally {
            try {
                $this->runtime->stop();
            } finally {
                try {
                    $this->runtime->clear();
                } finally {
                    $this->collecting = false;
                }
            }
        }

        return new RawCoverage($this->normalize($collected));
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

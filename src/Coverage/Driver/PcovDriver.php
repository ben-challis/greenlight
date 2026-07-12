<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Driver;

use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\RawCoverage;

/**
 * Line coverage via the pcov extension.
 *
 * pcov reports each seen line as covered (one) or executable but not executed
 * (minus one); it has no dead code detection.
 *
 * Collected state is cleared on every stop() so consecutive collection
 * windows do not bleed into each other.
 *
 * @internal
 */
final class PcovDriver implements CoverageDriver
{
    private bool $collecting = false;

    public function __construct()
    {
        if (!self::isAvailable()) {
            throw CoverageError::driverUnavailable('pcov', 'Install and enable the pcov extension.');
        }
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
            throw new \LogicException('pcov collection window is already open; stop() must be called first.');
        }

        \pcov\start();
        $this->collecting = true;
    }

    #[\Override]
    public function stop(): RawCoverage
    {
        if (!$this->collecting) {
            throw new \LogicException('pcov collection window is not open; start() must be called first.');
        }

        $collected = \pcov\collect();
        \pcov\stop();
        \pcov\clear();
        $this->collecting = false;

        return new RawCoverage($this->normalise($collected));
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

            $statuses = \array_filter($fileLines, fn($status, $line) => \is_int($line) && \is_int($status), \ARRAY_FILTER_USE_BOTH);

            $lines[$path] = $statuses;
        }

        return $lines;
    }
}

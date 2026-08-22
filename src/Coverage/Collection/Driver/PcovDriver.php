<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Collection\Driver;

use Greenlight\Coverage\Collection\RawCoverage;
use Greenlight\Coverage\CoverageError;

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

    /**
     * @throws CoverageError
     */
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

        return new RawCoverage($collected);
    }
}

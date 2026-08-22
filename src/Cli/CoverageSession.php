<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Runner\CoverageCollector;
use Greenlight\Runner\CoverageSettings;
use Greenlight\Runner\SharedCoverageDirectory;

/**
 * Owns and merges the coverage resources for one CLI run.
 *
 * @internal
 */
final class CoverageSession
{
    private ?CoverageCollector $collector = null;

    private bool $collecting = false;

    private ?SharedCoverageDirectory $shared = null;

    private function __construct() {}

    /**
     * @throws CoverageError
     */
    public static function open(
        ?CoverageSettings $settings,
        bool $collectProcess,
        ?string $temporaryDirectory = null,
    ): self {
        $session = new self();

        if (!$settings instanceof CoverageSettings) {
            return $session;
        }

        try {
            if ($collectProcess) {
                $session->collector = CoverageCollector::create($settings);

                if ($session->collector instanceof CoverageCollector) {
                    $session->collector->start();
                    $session->collecting = true;
                }
            }

            $session->shared = SharedCoverageDirectory::open($settings, $temporaryDirectory);
        } catch (\Throwable $failure) {
            $session->close();

            throw $failure;
        }

        return $session;
    }

    public function finish(?CoverageMap $coverage): ?CoverageMap
    {
        if ($this->collecting) {
            $this->collecting = false;
            $collected = $this->collector?->stop();

            if ($collected instanceof CoverageMap && !$collected->isEmpty()) {
                $coverage = $coverage instanceof CoverageMap ? $coverage->merge($collected) : $collected;
            }
        }

        if ($this->shared instanceof SharedCoverageDirectory) {
            $shared = $this->shared;
            $this->shared = null;
            $dumped = $shared->drain();

            if ($dumped instanceof CoverageMap) {
                $coverage = $coverage instanceof CoverageMap ? $coverage->merge($dumped) : $dumped;
            }
        }

        return $coverage;
    }

    public function close(): void
    {
        if ($this->collecting) {
            $this->collecting = false;

            try {
                $this->collector?->stop();
            } catch (\Throwable) {
                // A cleanup failure MUST not replace the run failure.
            }
        }

        if ($this->shared instanceof SharedCoverageDirectory) {
            $shared = $this->shared;
            $this->shared = null;

            try {
                $shared->drain();
            } catch (\Throwable) {
                // A cleanup failure MUST not replace the run failure.
            }
        }
    }
}

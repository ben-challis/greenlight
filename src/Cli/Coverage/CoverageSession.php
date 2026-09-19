<?php

declare(strict_types=1);

namespace Greenlight\Cli\Coverage;

use Greenlight\Coverage\Collection\CoverageCollector;
use Greenlight\Coverage\Collection\CoverageSettings;
use Greenlight\Coverage\CoverageError;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Relay\SharedCoverageDirectory;

/**
 * Owns and merges the coverage resources for one CLI run.
 *
 * @internal
 */
final class CoverageSession
{
    private ?CoverageCollector $collector = null;

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
                $collector = CoverageCollector::create($settings);

                if ($collector instanceof CoverageCollector) {
                    $collector->start();
                    $session->collector = $collector;
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
        if ($this->collector instanceof CoverageCollector) {
            $collector = $this->collector;
            $this->collector = null;
            $collected = $collector->stop();

            if (!$collected->isEmpty()) {
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
        if ($this->collector instanceof CoverageCollector) {
            $collector = $this->collector;
            $this->collector = null;

            try {
                $collector->stop();
            } catch (\Throwable) {
                // Preserve the run failure if cleanup also fails.
            }
        }

        if ($this->shared instanceof SharedCoverageDirectory) {
            $shared = $this->shared;
            $this->shared = null;

            try {
                $shared->drain();
            } catch (\Throwable) {
                // Preserve the run failure if cleanup also fails.
            }
        }
    }
}

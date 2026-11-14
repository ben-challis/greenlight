<?php

declare(strict_types=1);

namespace Greenlight\Execution;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactRetention;

/**
 * Applies artifact retention without exposing run publication internals.
 *
 * @internal
 */
final readonly class ArtifactMaintenance
{
    private function __construct(private ArtifactRetention $retention) {}

    /** @throws AttachmentError */
    public static function forConfiguration(ArtifactConfiguration $configuration, string $workingDirectory): self
    {
        return new self(ArtifactRetention::forConfiguration($configuration, $workingDirectory));
    }

    /**
     * @param positive-int|null $abandonedRunAgeSeconds Also selects each
     *   incomplete run that no process holds and that started at least this
     *   many seconds ago.
     */
    public function prune(bool $dryRun, ?int $abandonedRunAgeSeconds = null): ArtifactMaintenanceReport
    {
        $report = $this->retention->prune($dryRun, abandonedRunAgeSeconds: $abandonedRunAgeSeconds);
        $items = [];
        foreach ($report->items as $item) {
            $items[] = new ArtifactMaintenanceItem($item->directory, $item->bytes, $item->reasons);
        }

        return new ArtifactMaintenanceReport($items, $report->warnings, $report->dryRun);
    }
}

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

    public function prune(bool $dryRun): ArtifactMaintenanceReport
    {
        $report = $this->retention->prune($dryRun);
        $items = [];
        foreach ($report->items as $item) {
            $items[] = new ArtifactMaintenanceItem($item->directory, $item->bytes, $item->reasons);
        }

        return new ArtifactMaintenanceReport($items, $report->warnings, $report->dryRun);
    }
}

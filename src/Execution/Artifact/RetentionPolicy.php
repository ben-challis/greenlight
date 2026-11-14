<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Config\ArtifactConfiguration;

/**
 * Selects runs for deletion from candidate records. This class does not use
 * the filesystem.
 *
 * select() applies the age, count, and size limits to completed records in
 * that order. Each limit selects the oldest remaining record first, by
 * completion time and then run ID. It also selects each active record, which
 * a caller supplies only for an explicit request to prune abandoned runs.
 * A run in the unselectable set is never selected, but a completed one
 * counts toward the count and size limits.
 *
 * @internal
 */
final readonly class RetentionPolicy
{
    public function __construct(private ArtifactConfiguration $configuration) {}

    /**
     * @param list<ArtifactRunRecord> $records
     * @param array<string, true> $unselectable
     *
     * @return list<array{ArtifactRunRecord, non-empty-list<'abandoned'|'age'|'count'|'size'>}>
     *   The selected records, completed records first, each in deletion order.
     */
    public function select(array $records, array $unselectable, int $now): array
    {
        $completed = \array_values(\array_filter($records, static fn(ArtifactRunRecord $record): bool => !$record->active));
        $abandoned = \array_values(\array_filter(
            $records,
            static fn(ArtifactRunRecord $record): bool => $record->active && !isset($unselectable[$record->runId]),
        ));
        \usort($completed, static fn(ArtifactRunRecord $a, ArtifactRunRecord $b): int => [$a->completedAt, $a->runId] <=> [$b->completedAt, $b->runId]);
        \usort($abandoned, static fn(ArtifactRunRecord $a, ArtifactRunRecord $b): int => [$a->startedAt, $a->runId] <=> [$b->startedAt, $b->runId]);

        /** @var array<non-empty-string, non-empty-list<'age'|'count'|'size'>> $selected */
        $selected = [];
        $age = $this->configuration->maxCompletedRunAgeSeconds;
        if ($age !== null) {
            foreach ($completed as $record) {
                if (!isset($unselectable[$record->runId]) && $record->completedAt <= $now && $now - $record->completedAt >= $age) {
                    $selected[$record->runId] = ['age'];
                }
            }
        }

        $remainingCount = \count($completed) - \count($selected);
        $countLimit = $this->configuration->maxCompletedRuns;
        if ($countLimit !== null) {
            foreach ($completed as $record) {
                if ($remainingCount <= $countLimit) {
                    break;
                }
                if (isset($unselectable[$record->runId]) || isset($selected[$record->runId])) {
                    continue;
                }
                $selected[$record->runId] = ['count'];
                --$remainingCount;
            }
        }

        $remainingBytes = 0;
        foreach ($completed as $record) {
            if (!isset($selected[$record->runId])) {
                $remainingBytes = RunManifest::saturatingAdd($remainingBytes, $record->bytes);
            }
        }
        $byteLimit = $this->configuration->maxRetainedBytes;
        if ($byteLimit !== null) {
            foreach ($completed as $record) {
                if ($remainingBytes <= $byteLimit) {
                    break;
                }
                if (isset($unselectable[$record->runId]) || isset($selected[$record->runId])) {
                    continue;
                }
                $selected[$record->runId] = ['size'];
                $remainingBytes = \max(0, $remainingBytes - $record->bytes);
            }
        }

        $selection = [];
        foreach ($completed as $record) {
            if (isset($selected[$record->runId])) {
                $selection[] = [$record, $selected[$record->runId]];
            }
        }
        foreach ($abandoned as $record) {
            $selection[] = [$record, ['abandoned']];
        }

        return $selection;
    }
}

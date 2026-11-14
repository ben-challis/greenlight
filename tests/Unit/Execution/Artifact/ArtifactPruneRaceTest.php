<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Artifact;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Execution\Artifact\ArtifactRetention;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;

use function Greenlight\expect;

/**
 * Changes a selected run while prune removes an earlier selected run. The
 * path remover runs between selection and the claim of the later run.
 */
final readonly class ArtifactPruneRaceTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function aRunThatAProcessLocksAfterSelectionIsKept(): void
    {
        $parent = $this->tempDirectory->subdirectory('race-locked');
        $retention = $this->abandonedRuns($parent);
        $lockPath = $parent . '/run-second/' . ArtifactRetention::LOCK_FILE;
        $locks = [];
        $locking = $retention->withPathRemoverForTesting(static function (string $path) use ($lockPath, &$locks): bool {
            if ($locks === []) {
                $lock = \fopen($lockPath, 'r+');
                if ($lock === false || !\flock($lock, \LOCK_EX)) {
                    throw new \RuntimeException('The test did not lock the run.');
                }
                $locks[] = $lock;
            }

            return self::remove($path);
        });
        $this->cleanup->defer(static function () use (&$locks): void {
            foreach ($locks as $lock) {
                \fclose($lock);
            }
        });

        $report = $locking->prune(now: 100, abandonedRunAgeSeconds: 50);

        expect(\is_dir($parent . '/run-second'))
            ->because('prune MUST NOT remove a run that a process locked after selection')
            ->toBeTrue();
        expect(\array_map(static fn($item): string => $item->runId, $report->items))->toBe(['run-first']);
        expect($report->warnings)->toBe(['Greenlight did not prune artifact run "run-second".']);
    }

    #[Test]
    public function aRunThatCompletesAfterSelectionIsRestored(): void
    {
        $parent = $this->tempDirectory->subdirectory('race-completed');
        $retention = $this->abandonedRuns($parent);
        $second = $parent . '/run-second';
        $completed = false;
        $completing = $retention->withPathRemoverForTesting(function (string $path) use ($second, &$completed): bool {
            if (!$completed) {
                $this->rewriteMetadata($second, ['state' => 'completed', 'completedAt' => 99, 'files' => []]);
                $completed = true;
            }

            return self::remove($path);
        });

        $report = $completing->prune(now: 100, abandonedRunAgeSeconds: 50);

        expect(\is_file($second . '/evidence.txt'))
            ->because('prune MUST restore a run that completed after its selection as abandoned')
            ->toBeTrue();
        expect(\array_map(static fn($item): string => $item->runId, $report->items))->toBe(['run-first']);
        expect($report->warnings)->toBe(['Greenlight did not prune artifact run "run-second".']);
    }

    private function abandonedRuns(string $parent): ArtifactRetention
    {
        $retention = ArtifactRetention::forConfiguration(new ArtifactConfiguration(directory: $parent), $parent);
        foreach (['run-first' => 10, 'run-second' => 20] as $runId => $startedAt) {
            $handle = $retention->begin($runId);
            \file_put_contents($handle->directory . '/evidence.txt', $runId);
            $handle->close();
            $this->rewriteMetadata($handle->directory, ['startedAt' => $startedAt]);
        }

        return $retention;
    }

    private static function remove(string $path): bool
    {
        return \is_dir($path) && !\is_link($path) ? \rmdir($path) : \unlink($path);
    }

    /** @param array<string, mixed> $changes */
    private function rewriteMetadata(string $directory, array $changes): void
    {
        $metadata = \json_decode(
            (string) \file_get_contents($directory . '/' . ArtifactRetention::METADATA_FILE),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );
        if (!\is_array($metadata)) {
            throw new \RuntimeException('The test did not decode artifact metadata.');
        }
        $map = [];
        foreach ($metadata as $key => $value) {
            $map[(string) $key] = $value;
        }
        ArtifactRetention::writeMetadata($directory, [...$map, ...$changes]);
    }
}

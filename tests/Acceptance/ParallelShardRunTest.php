<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;
use Greenlight\Tests\Support\ProcessResult;

final readonly class ParallelShardRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function parallelShardsReconstituteTheFullRunExactlyOnce(): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'parallel-sharding');
        $all = GreenlightCli::run($project->directory, ['run', '--workers=2', '--reporter=jsonl']);
        $first = GreenlightCli::run(
            $project->directory,
            ['run', '--workers=2', '--reporter=jsonl', '--shard=1/2'],
        );
        $second = GreenlightCli::run(
            $project->directory,
            ['run', '--workers=2', '--reporter=jsonl', '--shard=2/2'],
        );
        $allIds = $this->finishedTestIds($all);
        $firstIds = $this->finishedTestIds($first);
        $secondIds = $this->finishedTestIds($second);
        $union = [...$firstIds, ...$secondIds];

        \sort($allIds);
        \sort($union);

        Expect::that($all->exitCode)
            ->because('the unsharded parallel run MUST succeed')
            ->toBe(0)
            ->and($first->exitCode)
            ->because('parallel shard one MUST succeed')
            ->toBe(0)
            ->and($second->exitCode)
            ->because('parallel shard two MUST succeed')
            ->toBe(0)
            ->and($firstIds)
            ->because('parallel shard one MUST contain tests')
            ->not()
            ->toHaveCount(0)
            ->and($secondIds)
            ->because('parallel shard two MUST contain tests')
            ->not()
            ->toHaveCount(0)
            ->and(\array_intersect($firstIds, $secondIds))
            ->because('parallel shards MUST NOT execute the same test')
            ->toBe([])
            ->and($union)
            ->because('parallel shards MUST reconstitute the full run exactly once')
            ->toBe($allIds);
    }

    /**
     * @return list<string>
     */
    private function finishedTestIds(ProcessResult $result): array
    {
        $ids = [];

        foreach (JsonlEvents::from($result) as $event) {
            if ($event instanceof TestFinished) {
                $ids[] = (string) $event->result->id;
            }
        }

        return $ids;
    }
}

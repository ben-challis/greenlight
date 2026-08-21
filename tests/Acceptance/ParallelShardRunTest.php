<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;

final readonly class ParallelShardRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

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
        $allIds = JsonlEvents::finishedTestIds($all);
        $firstIds = JsonlEvents::finishedTestIds($first);
        $secondIds = JsonlEvents::finishedTestIds($second);
        $union = [...$firstIds, ...$secondIds];

        \sort($allIds);
        \sort($union);

        Expect::that($all->exitCode)
            ->because('the unsharded parallel run MUST succeed')
            ->toBe(0);
        Expect::that($first->exitCode)
            ->because('parallel shard one MUST succeed')
            ->toBe(0);
        Expect::that($second->exitCode)
            ->because('parallel shard two MUST succeed')
            ->toBe(0);
        Expect::that($firstIds)
            ->because('parallel shard one MUST contain tests')
            ->not()
            ->toHaveCount(0);
        Expect::that($secondIds)
            ->because('parallel shard two MUST contain tests')
            ->not()
            ->toHaveCount(0);
        Expect::that(\array_intersect($firstIds, $secondIds))
            ->because('parallel shards MUST NOT execute the same test')
            ->toBe([]);
        Expect::that($union)
            ->because('parallel shards MUST reconstitute the full run exactly once')
            ->toBe($allIds);
    }

}

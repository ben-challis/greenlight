<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\AcceptanceProject;

/**
 * Sharding through the real CLI: the shards of list-tests reconstitute the
 * full suite exactly once, and malformed shard specs are usage errors.
 */
final class ShardingTest
{
    #[Test]
    public function shardsReconstituteTheFullListExactlyOnce(): void
    {
        // A private copy of ListTestsConfig, so these listings cannot race
        // another acceptance test's use of the same working directory.
        $project = AcceptanceProject::copyOfListTestsConfig('sharding');

        try {
            $all = $this->listTests($project);
            $union = [];

            foreach ([1, 2, 3] as $index) {
                foreach ($this->listTests($project, '--shard=' . $index . '/3') as $id) {
                    $union[] = $id;
                }
            }

            \sort($all);
            \sort($union);

            Expect::that($all)->not()->toHaveCount(0)
                ->and($union)->toBe($all);
        } finally {
            $project->remove();
        }
    }

    #[Test]
    public function malformedShardSpecsAreUsageErrors(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig('sharding');

        try {
            foreach (['--shard=4/3', '--shard=0/3', '--shard=banana'] as $flag) {
                [$exit, $output] = $project->run('list-tests', $flag);
                Expect::that($exit)->toBe(64)->and($output)->toContain('greenlight: --shard');
            }

            [, $output] = $project->run('list-tests', '--shard=4/3');
            Expect::that($output)->toContain('n must be between 1 and 3');
        } finally {
            $project->remove();
        }
    }

    /**
     * @return list<string>
     */
    private function listTests(AcceptanceProject $project, string ...$flags): array
    {
        [, $output] = $project->run('list-tests', ...$flags);
        $lines = \explode("\n", $output);

        return \array_values(\array_filter(
            $lines,
            static fn(string $line): bool => \str_contains($line, '::'),
        ));
    }
}

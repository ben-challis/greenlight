<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class ShardingTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function shardsReconstituteTheFullListExactlyOnce(): void
    {
        // A private copy of ListTestsConfig, so these listings cannot race
        // another acceptance test's use of the same working directory.
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'sharding');
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
    }

    #[Test]
    public function malformedShardSpecsAreUsageErrors(): void
    {
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'sharding');
        foreach (['--shard=4/3', '--shard=0/3', '--shard=banana'] as $flag) {
            $result = GreenlightCli::run($project->directory, ['list-tests', $flag]);
            Expect::that($result->exitCode)->toBe(64)->and($result->output())->toContain('greenlight: --shard');
        }
        $result = GreenlightCli::run($project->directory, ['list-tests', '--shard=4/3']);
        Expect::that($result->output())->toContain('n must be between 1 and 3');
    }

    /**
     * @return list<string>
     */
    private function listTests(AcceptanceProject $project, string ...$flags): array
    {
        $lines = GreenlightCli::run($project->directory, \array_values(['list-tests', ...$flags]))->outputLines();

        return \array_values(\array_filter(
            $lines,
            static fn(string $line): bool => \str_contains($line, '::'),
        ));
    }
}

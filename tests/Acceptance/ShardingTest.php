<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class ShardingTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function shardsReconstituteTheFullListExactlyOnce(): void
    {
        // An isolated project prevents a conflict with another acceptance
        // test in the same directory.
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'sharding');
        $all = $this->listTests($project);
        $union = [];
        foreach ([1, 2, 3] as $index) {
            foreach ($this->listTests($project, '--shard=' . $index . '/3') as $id) {
                $union[] = $id;
            }
        }
        \sort($all);
        \sort($union);
        Expect::that($all)->because('shards reconstitute the full list exactly once')->not()->toHaveCount(0);
        Expect::that($union)->because('shards reconstitute the full list exactly once')->toBe($all);
    }

    #[Test]
    #[DataSet('malformedShardSpecs')]
    public function malformedShardSpecsAreUsageErrors(string $flag, string $diagnostic): void
    {
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'sharding');
        $result = GreenlightCli::run($project->directory, ['list-tests', $flag]);

        Expect::that($result->exitCode)->because('malformed shard specs are usage errors')->toBe(64);
        Expect::that($result->output())->because('malformed shard specs are usage errors')->toBe($diagnostic);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function malformedShardSpecs(): iterable
    {
        yield 'index exceeds the shard count' => [
            '--shard=4/3',
            'greenlight: --shard requires 1 <= n <= m. Received "4/3". '
            . 'Valid n values for 3 shards are 1 through 3.',
        ];
        yield 'zero index' => [
            '--shard=0/3',
            'greenlight: --shard requires 1 <= n <= m. Received "0/3". '
            . 'Valid n values for 3 shards are 1 through 3.',
        ];
        yield 'invalid format' => [
            '--shard=banana',
            'greenlight: --shard requires <n>/<m>, such as 1/4. Received "banana".',
        ];
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

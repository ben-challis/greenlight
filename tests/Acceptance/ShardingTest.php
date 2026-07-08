<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

/**
 * Sharding through the real CLI: the shards of list-tests reconstitute the
 * full suite exactly once, and malformed shard specs are usage errors.
 */
final class ShardingTest
{
    #[Test]
    public function shardsReconstituteTheFullListExactlyOnce(): void
    {
        $all = $this->listTests();
        $union = [];

        foreach ([1, 2, 3] as $index) {
            foreach ($this->listTests('--shard=' . $index . '/3') as $id) {
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
        foreach (['--shard=4/3', '--shard=0/3', '--shard=banana'] as $flag) {
            [$exit, $output] = $this->run($flag);
            Expect::that($exit)->toBe(64)->and($output)->toContain('--shard');
        }
    }

    /**
     * @return list<string>
     */
    private function listTests(string ...$flags): array
    {
        [, $output] = $this->run(...$flags);
        $lines = \explode("\n", $output);

        return \array_values(\array_filter(
            $lines,
            static fn(string $line): bool => \str_contains($line, '::'),
        ));
    }

    /**
     * @return array{int, string}
     */
    private function run(string ...$flags): array
    {
        $root = \dirname(__DIR__, 2);
        $parts = [\escapeshellarg(\PHP_BINARY), \escapeshellarg($root . '/bin/greenlight'), 'list-tests'];

        foreach ($flags as $flag) {
            $parts[] = \escapeshellarg($flag);
        }

        $command = \sprintf(
            'cd %s && %s 2>&1',
            \escapeshellarg($root . '/tests/Fixture/ListTestsConfig'),
            \implode(' ', $parts),
        );
        \exec($command, $output, $exit);

        return [$exit, \implode("\n", $output)];
    }
}

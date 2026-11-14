<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

use function Greenlight\expect;

final readonly class EmptyShardTest
{
    private const int SHARDS = 4;

    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function aShardWithoutTestsSucceedsWhenOtherShardsHaveTests(): void
    {
        $project = AcceptanceProject::createWithOnePassingTest($this->tempDirectory, 'empty-shard');
        $empty = 0;

        for ($index = 1; $index <= self::SHARDS; ++$index) {
            $result = GreenlightCli::run($project->directory, [
                'run',
                '--no-ansi',
                '--reporter=plain',
                \sprintf('--shard=%d/%d', $index, self::SHARDS),
            ]);

            expect($result->exitCode)
                ->because(\sprintf('shard %d of %d MUST succeed when the suite has tests', $index, self::SHARDS))
                ->toBe(0);

            if (\str_contains($result->stdout, \sprintf('Shard %d of %d has no tests.', $index, self::SHARDS))) {
                ++$empty;
                expect($result->stderr)
                    ->because('an empty shard is not a missing-tests error')
                    ->not()->toContain('Greenlight found no tests');
            }
        }

        expect($empty)
            ->because('one test class fills one shard and leaves the other shards empty')
            ->toBe(self::SHARDS - 1);
    }

    #[Test]
    public function aShardOfAnEmptySuiteStillFails(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'empty-suite-shard');
        $project->configureWithTestFiles([]);
        $project->writeFile('tests/.gitkeep', '');

        $result = GreenlightCli::run($project->directory, ['run', '--no-ansi', '--shard=1/2']);

        expect($result->exitCode)
            ->because('a suite without tests is still a configuration error')
            ->toBe(1);
        expect($result->stderr)->toContain('Greenlight found no tests');
    }
}

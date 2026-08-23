<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Attribution;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\Attribution\TestCoverageMap;
use Greenlight\Coverage\Attribution\TestCoverageMapError;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class TestCoverageMapImpactTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function returnsEachTestThatCoversAChangedLine(): void
    {
        $root = $this->tempDirectory->subdirectory('coverage-impact');
        $source = $root . '/src/Subject.php';
        $artifact = $root . '/coverage.jsonl';
        $this->write($artifact, [
            ['v' => 1, 'type' => 'meta', 'root' => $root, 'runId' => 'run-one', 'complete' => true],
            ['v' => 1, 'type' => 'test', 'test' => 0, 'renderedId' => 'Example\\FirstTest::runs'],
            ['v' => 1, 'type' => 'test', 'test' => 1, 'renderedId' => 'Example\\SecondTest::runs'],
            ['v' => 1, 'type' => 'coverage', 'test' => 0, 'file' => $source, 'lines' => [4, 8]],
            ['v' => 1, 'type' => 'coverage', 'test' => 1, 'file' => $source, 'lines' => [8]],
            ['v' => 1, 'type' => 'source', 'file' => $source, 'covered' => true, 'lines' => [4, 8]],
        ]);

        Expect::that(TestCoverageMap::impactedTests($artifact, $root, 'run-one', [$source => [8]]))
            ->because('each test that covered the changed line MUST be selected')
            ->toBe(['Example\\FirstTest::runs', 'Example\\SecondTest::runs']);
    }

    #[Test]
    public function rejectsAMapFromAnOlderRun(): void
    {
        $root = $this->tempDirectory->subdirectory('coverage-impact-stale');
        $source = $root . '/src/Subject.php';
        $artifact = $root . '/coverage.jsonl';
        $this->write($artifact, [
            ['v' => 1, 'type' => 'meta', 'root' => $root, 'runId' => 'old-run', 'complete' => true],
        ]);

        Expect::that(
            static fn(): array => TestCoverageMap::impactedTests($artifact, $root, 'current-run', [$source => [8]]),
        )
            ->because('a map from an older run MUST require the complete selected plan')
            ->toThrow(TestCoverageMapError::class);
    }

    /** @param list<array<string, mixed>> $extraRecords */
    #[Test]
    #[DataSet('uncertainMaps')]
    public function rejectsAnUncertainChangedLine(array $extraRecords): void
    {
        $root = $this->tempDirectory->subdirectory('coverage-impact-uncertain-' . \count($extraRecords));
        $source = $root . '/src/Subject.php';
        $artifact = $root . '/coverage.jsonl';
        $records = [
            ['v' => 1, 'type' => 'meta', 'root' => $root, 'runId' => 'run-two', 'complete' => true],
            ['v' => 1, 'type' => 'test', 'test' => 0, 'renderedId' => 'Example\\SubjectTest::runs'],
        ];

        foreach ($extraRecords as $record) {
            $record['file'] = $source;
            $records[] = $record;
        }
        $this->write($artifact, $records);

        Expect::that(
            static fn(): array => TestCoverageMap::impactedTests($artifact, $root, 'run-two', [$source => [8]]),
        )
            ->because('uncertain line attribution MUST require a complete selected run')
            ->toThrow(TestCoverageMapError::class);
    }

    /** @return iterable<string, array{list<array<string, mixed>>}> */
    public static function uncertainMaps(): iterable
    {
        yield 'uncovered line' => [[
            ['v' => 1, 'type' => 'source', 'covered' => false, 'lines' => [8]],
        ]];
        yield 'unattributed line' => [[
            ['v' => 1, 'type' => 'source', 'covered' => true, 'lines' => [8]],
            ['v' => 1, 'type' => 'unattributed', 'lines' => [8]],
        ]];
        yield 'missing coverage record' => [[
            ['v' => 1, 'type' => 'source', 'covered' => true, 'lines' => [8]],
        ]];
        yield 'missing source record' => [[
            ['v' => 1, 'type' => 'coverage', 'test' => 0, 'lines' => [8]],
        ]];
    }

    /** @param list<array<string, mixed>> $records */
    private function write(string $artifact, array $records): void
    {
        $lines = \array_map(
            static fn(array $record): string => \json_encode($record, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES),
            $records,
        );
        \file_put_contents($artifact, \implode("\n", $lines) . "\n");
    }
}

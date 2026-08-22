<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\PathFilter;
use Greenlight\Coverage\RawCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;
use Greenlight\Wire\InvalidWirePayload;

final class CoverageMapTest
{
    #[Test]
    public function fromRawSplitsStatusesAndDropsDeadCode(): void
    {
        $map = CoverageMap::fromRaw(new RawCoverage([
            '/src/A.php' => [3 => 1, 4 => -1, 5 => -2, 6 => 7],
        ]));

        $file = $map->files()['/src/A.php'];

        Expect::that($file->coveredLines)->because('from raw splits statuses and drops dead code')->toBe([3, 6]);
        Expect::that($file->uncoveredLines)->toBe([4]);
    }

    #[Test]
    public function fromRawAppliesThePathFilter(): void
    {
        $raw = new RawCoverage([
            '/project/src/A.php' => [1 => 1],
            '/project/vendor/dep/B.php' => [1 => 1],
        ]);

        $map = CoverageMap::fromRaw($raw, new PathFilter(['/project/src']));

        Expect::that(\array_keys($map->files()))->because('from raw applies the path filter')->toBe(['/project/src/A.php']);
    }

    #[Test]
    public function fromRawDropsFilesWithNoExecutableLines(): void
    {
        $map = CoverageMap::fromRaw(new RawCoverage(['/src/A.php' => [3 => -2]]));

        Expect::that($map->isEmpty())->because('from raw drops files with no executable lines')->toBeTrue();
    }

    #[Test]
    public function fromRawDropsInvalidLineNumbers(): void
    {
        $map = CoverageMap::fromRaw(new RawCoverage([
            '/src/A.php' => [-1 => 1, 0 => -1, 1 => 1],
        ]));

        Expect::that($map->files()['/src/A.php']->coveredLines)
            ->because('raw coverage drops non-positive line numbers')
            ->toBe([1]);
        Expect::that($map->files()['/src/A.php']->uncoveredLines)
            ->toBe([]);
    }

    #[Test]
    public function fromRawDropsUnusableDriverEntries(): void
    {
        $map = CoverageMap::fromRaw(new RawCoverage([
            '' => [1 => 1],
            '/src/A.php' => [1 => 0, 2 => -2, 3 => 1, 4 => -1],
        ]));

        Expect::that($map->toWire())
            ->because('raw coverage MUST keep only usable paths and documented driver statuses')
            ->toBe([
                'files' => [
                    '/src/A.php' => [[3], [4]],
                ],
            ]);
    }

    #[Test]
    public function filesAreSortedByPath(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/b.php', [1], []),
            new FileCoverage('/src/a.php', [1], []),
        ]);

        Expect::that(\array_keys($map->files()))->because('files are sorted by path')->toBe(['/src/a.php', '/src/b.php']);
    }

    #[Test]
    public function mergeIsIdempotent(): void
    {
        $a = $this->sampleA();

        Expect::that($a->merge($a)->toWire())->because('merge is idempotent')->toBe($a->toWire());
    }

    #[Test]
    public function mergeIsAssociative(): void
    {
        $a = $this->sampleA();
        $b = $this->sampleB();
        $c = $this->sampleC();

        $left = $a->merge($b)->merge($c);
        $right = $a->merge($b->merge($c));

        Expect::that($left->toWire())->because('merge is associative')->toBe($right->toWire());
    }

    #[Test]
    public function mergeIsCommutative(): void
    {
        $a = $this->sampleA();
        $b = $this->sampleB();

        Expect::that($a->merge($b)->toWire())->because('merge is commutative')->toBe($b->merge($a)->toWire());
    }

    #[Test]
    public function coveredWinsOverUncoveredAcrossMerges(): void
    {
        $sawItUncovered = new CoverageMap([new FileCoverage('/src/A.php', [], [10])]);
        $sawItCovered = new CoverageMap([new FileCoverage('/src/A.php', [10], [])]);

        $file = $sawItUncovered->merge($sawItCovered)->files()['/src/A.php'];

        Expect::that($file->coveredLines)->because('covered wins over uncovered across merges')->toBe([10]);
        Expect::that($file->uncoveredLines)->toBe([]);
    }

    #[Test]
    public function percentagesAggregateAcrossFiles(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [1, 2, 3], [4]),
            new FileCoverage('/src/B.php', [1], [2, 3, 4]),
        ]);

        Expect::that($map->coveredLineTotal())->because('percentages aggregate across files')->toBe(4);
        Expect::that($map->executableLineTotal())->toBe(8);
        Expect::that($map->totalPercentage())->toBeWithin(0.001, 50.0);
    }

    #[Test]
    public function emptyMapCountsAsFullyCovered(): void
    {
        Expect::that(CoverageMap::empty()->totalPercentage())->because('empty map counts as fully covered')->toBe(100.0);
    }

    #[Test]
    public function wirePayloadSurvivesAJsonRoundTrip(): void
    {
        $map = $this->sampleA()->merge($this->sampleB());

        $restored = CoverageMap::fromWire(JsonWire::roundTrip($map->toWire()));

        Expect::that($restored->toWire())->because('wire payload survives a JSON round trip')->toBe($map->toWire());
    }

    #[Test]
    public function emptyMapSurvivesAJsonRoundTrip(): void
    {
        $restored = CoverageMap::fromWire(JsonWire::roundTrip(CoverageMap::empty()->toWire()));

        Expect::that($restored->isEmpty())->because('empty map survives a JSON round trip')->toBeTrue();
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[Test]
    #[DataSet('malformedWirePayloads')]
    public function malformedWirePayloadsAreRejected(array $payload, string $message): void
    {
        Expect::that(static fn(): CoverageMap => CoverageMap::fromWire($payload))
            ->because('malformed coverage-map wire payloads are rejected')
            ->toThrow(InvalidWirePayload::class, $message);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, non-empty-string}>
     */
    public static function malformedWirePayloads(): iterable
    {
        yield 'missing files' => [[], '/missing the "files" key/'];
        yield 'empty file path' => [
            ['files' => ['' => [[], []]]],
            '/non-empty file paths/',
        ];
        yield 'numeric file path' => [
            ['files' => [0 => [[1], []], '/src/A.php' => [[1], []]]],
            '/map with string keys/',
        ];
        yield 'wrong file shape' => [
            ['files' => ['/src/A.php' => [[1]]]],
            '/two-element list/',
        ];
        yield 'line set is not a list' => [
            ['files' => ['/src/A.php' => [['line' => 1], []]]],
            '/list of line numbers/',
        ];
        yield 'invalid line number' => [
            ['files' => ['/src/A.php' => [['one'], []]]],
            '/positive line numbers/',
        ];
        yield 'zero line number' => [
            ['files' => ['/src/A.php' => [[0], []]]],
            '/positive line numbers/',
        ];
        yield 'negative line number' => [
            ['files' => ['/src/A.php' => [[-1], []]]],
            '/positive line numbers/',
        ];
    }

    private function sampleA(): CoverageMap
    {
        return new CoverageMap([
            new FileCoverage('/src/A.php', [1, 2], [3, 4]),
            new FileCoverage('/src/B.php', [10], [11]),
        ]);
    }

    private function sampleB(): CoverageMap
    {
        return new CoverageMap([
            new FileCoverage('/src/A.php', [3], [1, 5]),
            new FileCoverage('/src/C.php', [], [7]),
        ]);
    }

    private function sampleC(): CoverageMap
    {
        return new CoverageMap([
            new FileCoverage('/src/B.php', [11], []),
            new FileCoverage('/src/C.php', [7, 8], []),
        ]);
    }
}

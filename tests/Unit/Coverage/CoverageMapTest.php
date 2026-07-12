<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Coverage\PathFilter;
use Greenlight\Coverage\RawCoverage;
use Greenlight\Expect\Expect;

final class CoverageMapTest
{
    #[Test]
    public function fromRawSplitsStatusesAndDropsDeadCode(): void
    {
        $map = CoverageMap::fromRaw(new RawCoverage([
            '/src/A.php' => [3 => 1, 4 => -1, 5 => -2, 6 => 7],
        ]));

        $file = $map->files()['/src/A.php'];

        Expect::that($file->coveredLines)->toBe([3, 6])
            ->and($file->uncoveredLines)->toBe([4]);
    }

    #[Test]
    public function fromRawAppliesThePathFilter(): void
    {
        $raw = new RawCoverage([
            '/project/src/A.php' => [1 => 1],
            '/project/vendor/dep/B.php' => [1 => 1],
        ]);

        $map = CoverageMap::fromRaw($raw, new PathFilter(['/project/src']));

        Expect::that(\array_keys($map->files()))->toBe(['/project/src/A.php']);
    }

    #[Test]
    public function fromRawDropsFilesWithNoExecutableLines(): void
    {
        $map = CoverageMap::fromRaw(new RawCoverage(['/src/A.php' => [3 => -2]]));

        Expect::that($map->isEmpty())->toBeTrue();
    }

    #[Test]
    public function filesAreSortedByPath(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/b.php', [1], []),
            new FileCoverage('/src/a.php', [1], []),
        ]);

        Expect::that(\array_keys($map->files()))->toBe(['/src/a.php', '/src/b.php']);
    }

    #[Test]
    public function mergeIsIdempotent(): void
    {
        $a = $this->sampleA();

        Expect::that($a->merge($a)->toWire())->toBe($a->toWire());
    }

    #[Test]
    public function mergeIsAssociative(): void
    {
        $a = $this->sampleA();
        $b = $this->sampleB();
        $c = $this->sampleC();

        $left = $a->merge($b)->merge($c);
        $right = $a->merge($b->merge($c));

        Expect::that($left->toWire())->toBe($right->toWire());
    }

    #[Test]
    public function mergeIsCommutative(): void
    {
        $a = $this->sampleA();
        $b = $this->sampleB();

        Expect::that($a->merge($b)->toWire())->toBe($b->merge($a)->toWire());
    }

    #[Test]
    public function coveredWinsOverUncoveredAcrossMerges(): void
    {
        $sawItUncovered = new CoverageMap([new FileCoverage('/src/A.php', [], [10])]);
        $sawItCovered = new CoverageMap([new FileCoverage('/src/A.php', [10], [])]);

        $file = $sawItUncovered->merge($sawItCovered)->files()['/src/A.php'];

        Expect::that($file->coveredLines)->toBe([10])
            ->and($file->uncoveredLines)->toBe([]);
    }

    #[Test]
    public function percentagesAggregateAcrossFiles(): void
    {
        $map = new CoverageMap([
            new FileCoverage('/src/A.php', [1, 2, 3], [4]),
            new FileCoverage('/src/B.php', [1], [2, 3, 4]),
        ]);

        Expect::that($map->coveredLineTotal())->toBe(4)
            ->and($map->executableLineTotal())->toBe(8)
            ->and($map->totalPercentage())->toBeWithin(0.001, 50.0);
    }

    #[Test]
    public function emptyMapCountsAsFullyCovered(): void
    {
        Expect::that(CoverageMap::empty()->totalPercentage())->toBe(100.0);
    }

    #[Test]
    public function wirePayloadSurvivesAJsonRoundTrip(): void
    {
        $map = $this->sampleA()->merge($this->sampleB());

        $decoded = \json_decode(\json_encode($map->toWire(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        /** @var array<string, mixed> $decoded */
        $restored = CoverageMap::fromWire($decoded);

        Expect::that($restored->toWire())->toBe($map->toWire());
    }

    #[Test]
    public function emptyMapSurvivesAJsonRoundTrip(): void
    {
        $decoded = \json_decode(\json_encode(CoverageMap::empty()->toWire(), \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        /** @var array<string, mixed> $decoded */
        $restored = CoverageMap::fromWire($decoded);

        Expect::that($restored->isEmpty())->toBeTrue();
    }

    #[Test]
    public function malformedWirePayloadsAreRejected(): void
    {
        Expect::that(static fn(): CoverageMap => CoverageMap::fromWire([]))
            ->toThrow(InvalidWirePayload::class)
            ->and(static fn(): CoverageMap => CoverageMap::fromWire(['files' => ['/src/A.php' => [[1]]]]))
            ->toThrow(InvalidWirePayload::class, '/two-element list/')
            ->and(static fn(): CoverageMap => CoverageMap::fromWire(['files' => ['/src/A.php' => [['one'], []]]]))
            ->toThrow(InvalidWirePayload::class, '/positive line numbers/');
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

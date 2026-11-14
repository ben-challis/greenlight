<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\CoverageMapBuilder;
use Greenlight\Coverage\FileCoverage;

use function Greenlight\expect;

final readonly class CoverageMapBuilderTest
{
    #[Test]
    public function theBuiltMapEqualsTheMergeOfEachAddedMap(): void
    {
        $maps = [
            new CoverageMap([new FileCoverage('/src/c.php', [1], [2]), new FileCoverage('/src/a.php', [], [3])]),
            CoverageMap::empty(),
            new CoverageMap([new FileCoverage('/src/a.php', [3], [4])]),
            new CoverageMap([new FileCoverage('/src/b.php', [5], [])]),
        ];
        $builder = new CoverageMapBuilder();
        $merged = CoverageMap::empty();

        foreach ($maps as $map) {
            $builder->add($map);
            $merged = $merged->merge($map);
        }

        expect($builder->build()->toWire())
            ->because('the builder MUST produce the same map as sequential merges')
            ->toBe($merged->toWire());
        expect(\array_keys($builder->build()->files()))
            ->toBe(['/src/a.php', '/src/b.php', '/src/c.php']);
    }

    #[Test]
    public function anAddAfterABuildChangesTheNextBuild(): void
    {
        $builder = new CoverageMapBuilder();
        $builder->add(new CoverageMap([new FileCoverage('/src/a.php', [1], [])]));
        $first = $builder->build();
        $builder->add(new CoverageMap([new FileCoverage('/src/a.php', [], [2])]));

        expect($first->files()['/src/a.php']->uncoveredLines)
            ->because('a built map MUST NOT change after a later add')
            ->toBe([]);
        expect($builder->build()->files()['/src/a.php']->uncoveredLines)->toBe([2]);
    }

    #[Test]
    public function theCostOfAnAddDoesNotGrowWithTheCollectedMap(): void
    {
        $small = $this->addCost(1_000);
        $large = $this->addCost(20_000);

        // A copy of the collected map for each add makes the large case about
        // 20 times slower than the small case.
        expect($large / $small)
            ->because('the cost of an add MUST NOT depend on the size of the collected map')
            ->toBeLessThan(5.0);
    }

    /**
     * Returns the fastest of three timings for 2,000 adds of a one-file report.
     *
     * @param positive-int $collectedFiles
     */
    private function addCost(int $collectedFiles): float
    {
        $report = new CoverageMap([new FileCoverage('/src/file1.php', [2], [])]);
        $best = \INF;

        for ($round = 0; $round < 3; ++$round) {
            $builder = new CoverageMapBuilder();
            $builder->add(new CoverageMap(\array_map(
                static fn(int $index): FileCoverage => new FileCoverage('/src/file' . $index . '.php', [1], []),
                \range(1, $collectedFiles),
            )));
            $start = \hrtime(true);

            for ($index = 0; $index < 2_000; ++$index) {
                $builder->add($report);
            }

            $best = \min($best, (float) (\hrtime(true) - $start));
            expect($builder->build()->files())->toHaveCount($collectedFiles);
        }

        return \max(1.0, $best);
    }
}

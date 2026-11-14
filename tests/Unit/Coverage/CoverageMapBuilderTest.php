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
    public function eachAddReadsOnlyTheIncomingFiles(): void
    {
        $builder = new CoverageMapBuilder();
        $builder->add(new CoverageMap(\array_map(
            static fn(int $index): FileCoverage => new FileCoverage('/src/file' . $index . '.php', [1], []),
            \range(1, 2_000),
        )));
        $report = new CoverageMap([new FileCoverage('/src/file1.php', [2], [])]);
        $start = \hrtime(true);

        for ($index = 0; $index < 2_000; ++$index) {
            $builder->add($report);
        }

        // A copy and sort of the complete map for each report takes seconds.
        expect((\hrtime(true) - $start) / 1_000_000_000)
            ->because('the cost of an add MUST NOT depend on the size of the collected map')
            ->toBeLessThan(1.0);
        expect($builder->build()->files())->toHaveCount(2_000);
    }
}

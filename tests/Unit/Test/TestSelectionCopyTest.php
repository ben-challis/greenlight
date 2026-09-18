<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Test\TestExclusions;
use Greenlight\Test\TestInclusions;
use Greenlight\Test\TestSelection;

use function Greenlight\expect;

final class TestSelectionCopyTest
{
    #[Test]
    public function focusedSelectionCopiesPreserveOtherCriteria(): void
    {
        $selection = new TestSelection(
            include: new TestInclusions(groups: ['fast'], idPatterns: ['*Invoice*']),
            exclude: new TestExclusions(groups: ['slow'], classes: ['Legacy*']),
            shard: [2, 3],
        );

        $selectedIds = $selection->withExactIds(['App\ExampleTest::runs']);
        $excludedPaths = $selectedIds->withExcludedPaths(['/project/generated']);

        expect($excludedPaths->include->exactIds)
            ->because('an exact-ID copy MUST remain after a path copy')
            ->toBe(['App\ExampleTest::runs']);
        expect($excludedPaths->exclude->paths)
            ->because('a path copy MUST replace only excluded paths')
            ->toBe(['/project/generated']);
        expect($excludedPaths->include->groups)->toBe(['fast']);
        expect($excludedPaths->include->idPatterns)->toBe(['*Invoice*']);
        expect($excludedPaths->exclude->groups)->toBe(['slow']);
        expect($excludedPaths->exclude->classes)->toBe(['Legacy*']);
        expect($excludedPaths->shard)->toBe([2, 3]);
        expect($selection->include->exactIds)
            ->because('a focused copy MUST leave its source unchanged')
            ->toBe([]);
    }
}

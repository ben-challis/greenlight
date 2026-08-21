<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestExclusions;
use Greenlight\Core\Test\TestInclusions;
use Greenlight\Core\Test\TestSelection;
use Greenlight\Expect\Expect;

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

        Expect::that($excludedPaths->include->exactIds)
            ->because('an exact-ID copy MUST remain after a path copy')
            ->toBe(['App\ExampleTest::runs']);
        Expect::that($excludedPaths->exclude->paths)
            ->because('a path copy MUST replace only excluded paths')
            ->toBe(['/project/generated']);
        Expect::that($excludedPaths->include->groups)->toBe(['fast']);
        Expect::that($excludedPaths->include->idPatterns)->toBe(['*Invoice*']);
        Expect::that($excludedPaths->exclude->groups)->toBe(['slow']);
        Expect::that($excludedPaths->exclude->classes)->toBe(['Legacy*']);
        Expect::that($excludedPaths->shard)->toBe([2, 3]);
        Expect::that($selection->include->exactIds)
            ->because('a focused copy MUST leave its source unchanged')
            ->toBe([]);
    }
}

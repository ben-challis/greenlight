<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\TestExclusions;
use Greenlight\Test\TestInclusions;
use Greenlight\Test\TestSelection;

final readonly class TestSelectionTest
{
    #[Test]
    public function resolvedDimensionsControlAcceptedTests(): void
    {
        $selection = new TestSelection(
            include: new TestInclusions(groups: ['fast'], idPatterns: ['*::works*'], exactIds: ['Acme\ExactTest::only']),
            exclude: new TestExclusions(['quarantined'], ['Legacy'], ['manual'], ['/vendor/tests']),
        );

        Expect::that($selection->accepts('Acme\FastTest', 'works', ['fast'], '/project/tests/FastTest.php'))->toBeTrue();
        Expect::that($selection->accepts('Acme\FastTest', 'works', ['slow'], '/project/tests/FastTest.php'))->toBeFalse();
        Expect::that($selection->accepts('Acme\FastTest', 'works', ['fast', 'quarantined'], '/project/tests/FastTest.php'))->toBeFalse();
        Expect::that($selection->accepts('Acme\LegacyTest', 'works', ['fast'], '/project/tests/LegacyTest.php'))->toBeFalse();
        Expect::that($selection->accepts('Acme\FastTest', 'manualCheck', ['fast'], '/project/tests/FastTest.php'))->toBeFalse();
        Expect::that($selection->accepts('Acme\FastTest', 'works', ['fast'], '/vendor/tests/FastTest.php'))->toBeFalse();
        Expect::that($selection->acceptsId('Acme\FastTest::worksNow'))->toBeTrue();
        Expect::that($selection->acceptsId('Acme\ExactTest::only'))->toBeTrue();
        Expect::that($selection->acceptsId('Acme\FastTest::other'))->toBeFalse();
    }

    #[Test]
    public function onlyExactIdsKeepNonIdFiltersAndTheShard(): void
    {
        $selection = new TestSelection(
            include: new TestInclusions(
                groups: ['fast'],
                classes: ['Acme'],
                methods: ['works'],
                paths: ['/project/tests'],
                idPatterns: ['*::broad*'],
            ),
            exclude: new TestExclusions(groups: ['quarantined']),
            shard: [2, 3],
        );

        $narrowed = $selection->withOnlyExactIds(['Acme\ExactTest::works']);

        Expect::that($narrowed->include->groups)->toBe(['fast']);
        Expect::that($narrowed->include->classes)->toBe(['Acme']);
        Expect::that($narrowed->include->methods)->toBe(['works']);
        Expect::that($narrowed->include->paths)->toBe(['/project/tests']);
        Expect::that($narrowed->include->idPatterns)->toBe([]);
        Expect::that($narrowed->include->exactIds)->toBe(['Acme\ExactTest::works']);
        Expect::that($narrowed->exclude->groups)->toBe(['quarantined']);
        Expect::that($narrowed->shard)->toBe([2, 3]);
    }
}

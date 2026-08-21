<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestExclusions;
use Greenlight\Core\Test\TestInclusions;
use Greenlight\Core\Test\TestSelection;
use Greenlight\Expect\Expect;

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
}

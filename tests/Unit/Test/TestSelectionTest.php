<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Test\TestExclusions;
use Greenlight\Test\TestInclusions;
use Greenlight\Test\TestSelection;

use function Greenlight\expect;

final readonly class TestSelectionTest
{
    #[Test]
    public function resolvedDimensionsControlAcceptedTests(): void
    {
        $selection = new TestSelection(
            include: new TestInclusions(groups: ['fast'], idPatterns: ['*::works*'], exactIds: ['Acme\ExactTest::only']),
            exclude: new TestExclusions(['quarantined'], ['Legacy'], ['manual'], ['/vendor/tests']),
        );

        expect($selection->accepts('Acme\FastTest', 'works', ['fast'], '/project/tests/FastTest.php'))->toBeTrue();
        expect($selection->accepts('Acme\FastTest', 'works', ['slow'], '/project/tests/FastTest.php'))->toBeFalse();
        expect($selection->accepts('Acme\FastTest', 'works', ['fast', 'quarantined'], '/project/tests/FastTest.php'))->toBeFalse();
        expect($selection->accepts('Acme\LegacyTest', 'works', ['fast'], '/project/tests/LegacyTest.php'))->toBeFalse();
        expect($selection->accepts('Acme\FastTest', 'manualCheck', ['fast'], '/project/tests/FastTest.php'))->toBeFalse();
        expect($selection->accepts('Acme\FastTest', 'works', ['fast'], '/vendor/tests/FastTest.php'))->toBeFalse();
        expect($selection->acceptsId('Acme\FastTest::worksNow'))->toBeTrue();
        expect($selection->acceptsId('Acme\ExactTest::only'))->toBeTrue();
        expect($selection->acceptsId('Acme\FastTest::other'))->toBeFalse();
    }
}

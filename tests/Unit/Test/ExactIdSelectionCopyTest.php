<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Test\TestInclusions;
use Greenlight\Test\TestSelection;

use function Greenlight\expect;

final class ExactIdSelectionCopyTest
{
    #[Test]
    public function exactIdCopiesReplaceMembershipAndPreservePatterns(): void
    {
        $original = new TestSelection(include: new TestInclusions(
            idPatterns: ['*::pattern'],
            exactIds: ['App\\ExampleTest::first'],
        ));
        $replacement = $original->withExactIds(['App\\ExampleTest::second']);
        $filtered = $replacement->withExcludedPaths(['/project/generated']);

        expect($original->acceptsId('App\\ExampleTest::first'))->toBeTrue();
        expect($original->acceptsId('App\\ExampleTest::second'))->toBeFalse();
        expect($filtered->acceptsId('App\\ExampleTest::first'))->toBeFalse();
        expect($filtered->acceptsId('App\\ExampleTest::second'))->toBeTrue();
        expect($filtered->acceptsId('App\\ExampleTest::pattern'))->toBeTrue();
        expect($filtered->acceptsId('app\\ExampleTest::second'))->toBeFalse();
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\TestInclusions;
use Greenlight\Test\TestSelection;

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

        Expect::that($original->acceptsId('App\\ExampleTest::first'))->toBeTrue();
        Expect::that($original->acceptsId('App\\ExampleTest::second'))->toBeFalse();
        Expect::that($filtered->acceptsId('App\\ExampleTest::first'))->toBeFalse();
        Expect::that($filtered->acceptsId('App\\ExampleTest::second'))->toBeTrue();
        Expect::that($filtered->acceptsId('App\\ExampleTest::pattern'))->toBeTrue();
        Expect::that($filtered->acceptsId('app\\ExampleTest::second'))->toBeFalse();
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Plugin;

use Greenlight\Attribute\Test;
use Greenlight\Plugin\TestPlan;
use Greenlight\Test\TestId;

use function Greenlight\expect;

final readonly class TestPlanTest
{
    #[Test]
    public function replacementPlansCanRemoveAndReorderCompleteClassBlocks(): void
    {
        $alpha = new TestId('Acme\\AlphaTest', 'passes');
        $betaFirst = new TestId('Acme\\BetaTest', 'first');
        $betaSecond = new TestId('Acme\\BetaTest', 'second');
        $plan = TestPlan::create([$alpha, $betaFirst, $betaSecond]);

        $replacement = $plan->withTests([$betaFirst, $betaSecond]);

        expect($replacement->tests)->toBe([$betaFirst, $betaSecond]);
        expect($plan->tests)->toBe([$alpha, $betaFirst, $betaSecond]);
    }

    #[Test]
    public function replacementPlansRejectDuplicateTests(): void
    {
        $test = new TestId('Acme\\ProbeTest', 'passes');
        $plan = TestPlan::create([$test]);

        expect()->calling(static fn(): TestPlan => $plan->withTests([$test, $test]))
            ->toThrow(\InvalidArgumentException::class, message: 'Test plan ID "Acme\\ProbeTest::passes" occurs more than once.');
    }

    #[Test]
    public function replacementPlansRejectSplitClassBlocks(): void
    {
        $plan = TestPlan::create([
            new TestId('Acme\\AlphaTest', 'first'),
            new TestId('Acme\\AlphaTest', 'second'),
            new TestId('Acme\\BetaTest', 'passes'),
        ]);

        expect()->calling(static fn(): TestPlan => $plan->withTests([
            $plan->tests[0],
            $plan->tests[2],
            $plan->tests[1],
        ]))->toThrow(
            \InvalidArgumentException::class,
            message: 'Test plan class "Acme\\AlphaTest" occurs in more than one block.',
        );
    }
}

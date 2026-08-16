<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;

final readonly class CallbackAnswerConflictTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function aReturnValueAfterACallbackIsRejected(): void
    {
        Expect::that(
            fn(): mixed => $this->doubles->mock(
                Calculator::class,
                static function (MockPlan $plan): void {
                    $plan->expects('add')
                        ->andReturnsUsing(static fn(): int => 1)
                        ->andReturns(2);
                },
            ),
        )
            ->because('a callback answer MUST prevent a second answer')
            ->toThrow(
                DoublesError::class,
                message: 'The expectation on add() already has an answer. Configure exactly one of '
                    . 'andReturns(), andReturnsSequence(), andReturnsUsing(), or andThrows().',
            );
    }
}

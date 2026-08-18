<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\Recorder;

final class DoublesDisposalAggregationTest
{
    #[Test]
    public function disposalAggregatesUnmetExpectationsAcrossMocksInCreationOrder(): void
    {
        $doubles = new Doubles();
        $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('add')->once()->andReturns(0);
        });
        $doubles->mock(Recorder::class, static function (MockPlan $plan): void {
            $plan->expects('record')->once();
        });

        Expect::that(static function () use ($doubles): void {
            $doubles->dispose();
        })
            ->because(
                'disposal MUST report unmet expectations from every mock in creation order',
            )
            ->toThrow(static function (ExpectationFailed $failure): void {
                Expect::that($failure->getMessage())->toBe(
                    "2 expectations failed:\n"
                    . '1) Calls to ' . Calculator::class . '::add(): 0 times. '
                    . "The expectation requires exactly 1 time.\n"
                    . '2) Calls to ' . Recorder::class . '::record(): 0 times. '
                    . 'The expectation requires exactly 1 time.',
                );

                Expect::that($failure->details)->toEqual([
                    new FailureDetail(
                        'Calls to ' . Calculator::class . '::add(): 0 times. '
                            . 'The expectation requires exactly 1 time.',
                        'add(all arguments) exactly 1 time',
                        'never called',
                    ),
                    new FailureDetail(
                        'Calls to ' . Recorder::class . '::record(): 0 times. '
                            . 'The expectation requires exactly 1 time.',
                        'record(all arguments) exactly 1 time',
                        'never called',
                    ),
                ]);
            });
    }
}

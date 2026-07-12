<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\FinalService;
use Greenlight\Tests\Fixture\Doubles\ReadonlyService;
use Greenlight\Tests\Fixture\Doubles\Suit;

final class BoundaryTest
{
    #[Test]
    public function finalClassesCannotBeDoubled(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): object => $doubles->mock(FinalService::class))
            ->toThrow(DoublesError::class, '/final and cannot be doubled.*interface/');
    }

    #[Test]
    public function readonlyClassesCannotBeDoubled(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): object => $doubles->mock(ReadonlyService::class))
            ->toThrow(DoublesError::class, '/readonly class.*interface/');
    }

    #[Test]
    public function enumsCannotBeDoubled(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): object => $doubles->mock(Suit::class))
            ->toThrow(DoublesError::class, '/is an enum/');
    }

    #[Test]
    public function planningAMissingMethodIsAnAuthoringError(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): object => $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('subtract');
        }))->toThrow(DoublesError::class, '/has no method subtract\(\)/');
    }
}

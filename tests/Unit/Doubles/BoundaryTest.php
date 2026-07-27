<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Calculator;
use Greenlight\Tests\Fixture\Doubles\FinalService;
use Greenlight\Tests\Fixture\Doubles\PlanningBoundaries;
use Greenlight\Tests\Fixture\Doubles\ReadonlyService;
use Greenlight\Tests\Fixture\Doubles\Suit;

final class BoundaryTest
{
    #[Test]
    public function finalClassesCannotBeDoubled(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): object => $doubles->mock(FinalService::class))->because('final classes cannot be doubled')
            ->toThrow(DoublesError::class, '/is final.*proxy subclass.*interface/');
    }

    #[Test]
    public function readonlyClassesCannotBeDoubled(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): object => $doubles->mock(ReadonlyService::class))->because('readonly classes cannot be doubled')
            ->toThrow(DoublesError::class, '/readonly class.*interface/');
    }

    #[Test]
    public function enumsCannotBeDoubled(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): object => $doubles->mock(Suit::class))->because('enums cannot be doubled')
            ->toThrow(
                DoublesError::class,
                message: 'Greenlight\Tests\Fixture\Doubles\Suit is an enum. '
                    . 'Doubles does not support enums. Use an interface that the enum implements.',
            );
    }

    #[Test]
    public function planningAMissingMethodIsAnAuthoringError(): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): object => $doubles->mock(Calculator::class, static function (MockPlan $plan): void {
            $plan->expects('subtract');
        }))->because('planning a missing method is an authoring error')->toThrow(DoublesError::class, '/has no method subtract\(\)/');
    }

    /**
     * @param non-empty-string $method
     */
    #[Test]
    #[DataSet('unplannableMethods')]
    public function planningAnUnplannableMethodIsAnAuthoringError(string $method, string $message): void
    {
        $doubles = new Doubles();

        Expect::that(static fn(): object => $doubles->mock(
            PlanningBoundaries::class,
            static function (MockPlan $plan) use ($method): void {
                $plan->expects($method);
            },
        ))
            ->because('planning an unplannable method is an authoring error')
            ->toThrow(DoublesError::class, message: $message);
    }

    /**
     * @return iterable<string, array{non-empty-string, string}>
     */
    public static function unplannableMethods(): iterable
    {
        yield 'static' => [
            'staticMethod',
            PlanningBoundaries::class . '::staticMethod() is static. Doubles cannot intercept static methods.',
        ];

        yield 'non-public' => [
            'protectedMethod',
            PlanningBoundaries::class . '::protectedMethod() is not public. Doubles cannot plan it.',
        ];

        yield 'final' => [
            'finalMethod',
            PlanningBoundaries::class . '::finalMethod() is final. Doubles cannot intercept it. Use an interface instead.',
        ];
    }
}

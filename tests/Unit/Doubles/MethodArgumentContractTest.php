<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Argument;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Doubles\MethodCallContract;
use Greenlight\Doubles\MockPlan;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Doubles\Wide;

final readonly class MethodArgumentContractTest
{
    public function __construct(private Doubles $doubles) {}

    #[Test]
    public function plannedArgumentsMustSatisfyTheMethodDeclaration(): void
    {
        Expect::that(fn(): Wide => $this->doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects(self::requiredMethod())->withNoArguments();
        }))->toThrow(
            InvalidDoubleUsage::class,
            '/withNoArguments\(\) supplies 0 arguments .* but the method requires 1 argument/',
        );

        Expect::that(fn(): Wide => $this->doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects(self::emptyMethod())->with(1);
        }))->toThrow(
            InvalidDoubleUsage::class,
            '/with\(\) supplies 1 argument .* but the method accepts at most 0 arguments/',
        );
    }

    #[Test]
    public function plannedPredicateTypeMustOverlapTheMethodParameterType(): void
    {
        Expect::that(fn(): PredicateTarget => $this->doubles->mock(
            PredicateTarget::class,
            static function (MockPlan $plan): void {
                $plan->expects(self::predicateMethod())->with(Argument::predicate(
                    static fn(PredicateBaz $value): bool => true,
                    'a baz',
                ));
            },
        ))->toThrow(
            InvalidDoubleUsage::class,
            '/matcher in with\(\) argument 1 accepts .*PredicateBaz.*parameter "\$value".*requires .*PredicateBar/',
        );
    }

    #[Test]
    public function plannedPredicateCanNarrowTheMethodParameterType(): void
    {
        $this->doubles->mock(PredicateTarget::class, static function (MockPlan $plan): void {
            $plan->expects('accept')->with(Argument::predicate(
                static fn(PredicateBarChild $value): bool => true,
                'a bar child',
            ))->never();
        });
    }

    #[Test]
    public function aDoubleRejectsArgumentsThatTheMethodDoesNotDeclare(): void
    {
        $wide = $this->doubles->mock(Wide::class, static function (MockPlan $plan): void {
            $plan->expects('returnsVoid')->never();
        });

        Expect::that(static fn(): mixed => new \ReflectionMethod($wide, 'returnsVoid')->invokeArgs($wide, [1]))
            ->toThrow(
                InvalidDoubleUsage::class,
                '/accepts at most 0 arguments/',
            );
    }

    #[Test]
    public function theCallContractRejectsMissingRequiredArguments(): void
    {
        $contract = MethodCallContract::from(Wide::class, 'unionType');

        Expect::that(static fn() => $contract->assertCallArgumentCount(0))
            ->toThrow(
                InvalidDoubleUsage::class,
                '/supplies 0 arguments, but the method requires 1 argument/',
            );
    }

    /** @return non-empty-string */
    private static function requiredMethod(): string
    {
        return 'unionType';
    }

    /** @return non-empty-string */
    private static function emptyMethod(): string
    {
        return 'returnsVoid';
    }

    /** @return non-empty-string */
    private static function predicateMethod(): string
    {
        return 'accept';
    }
}

interface PredicateTarget
{
    public function accept(PredicateBar $value): void;
}

class PredicateBar {}

final class PredicateBarChild extends PredicateBar {}

final class PredicateBaz {}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class NumericMatchersTest
{
    #[Test]
    public function toBeGreaterThanPasses(): void
    {
        Expect::that(3)->because('toBeGreaterThan() passes')->toBeGreaterThan(2);
        Expect::that(2.5)->because('toBeGreaterThan() passes')->toBeGreaterThan(2);
    }

    #[Test]
    public function toBeGreaterThanFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(2)->toBeGreaterThan(3),
        );

        Expect::that($detail->message)->because('toBeGreaterThan() fails')->toBe('Expected 2 to be greater than 3.');
        Expect::that($detail->expected)->because('toBeGreaterThan() fails')->toBe('greater than 3');
    }

    #[Test]
    public function toBeGreaterThanIsStrict(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(3)->toBeGreaterThan(3),
        );

        Expect::that($detail->message)->because('toBeGreaterThan() is strict')->toBe('Expected 3 to be greater than 3.');
    }

    #[Test]
    public function notToBeGreaterThan(): void
    {
        Expect::that(2)->because('not()->toBe() greater than')->not()->toBeGreaterThan(3);
        Expect::that(3)->because('not()->toBe() greater than')->not()->toBeGreaterThan(3);
    }

    #[Test]
    public function toBeGreaterThanGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('3')->toBeGreaterThan(2), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::that($detail->message)->because('toBeGreaterThan() guards the subject type')
            ->toBe('toBeGreaterThan() requires an int or float subject. The subject type is string.');
    }

    #[Test]
    public function toBeGreaterThanOrEqualPasses(): void
    {
        Expect::that(3)->because('toBeGreaterThanOrEqual() passes')->toBeGreaterThanOrEqual(2);
        Expect::that(3)->because('toBeGreaterThanOrEqual() passes')->toBeGreaterThanOrEqual(3);
        Expect::that(2.5)->because('toBeGreaterThanOrEqual() passes')->toBeGreaterThanOrEqual(2.5);
    }

    #[Test]
    public function toBeGreaterThanOrEqualFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(2)->toBeGreaterThanOrEqual(3),
        );

        Expect::that($detail->message)->because('toBeGreaterThanOrEqual() fails')->toBe('Expected 2 to be greater than or equal to 3.');
        Expect::that($detail->expected)->because('toBeGreaterThanOrEqual() fails')->toBe('greater than or equal to 3');
    }

    #[Test]
    public function notToBeGreaterThanOrEqual(): void
    {
        Expect::that(2)->because('not()->toBeGreaterThan() or equal')->not()->toBeGreaterThanOrEqual(3);
    }

    #[Test]
    public function toBeGreaterThanOrEqualGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('3')->toBeGreaterThanOrEqual(2), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::that($detail->message)->because('toBeGreaterThanOrEqual() guards the subject type')
            ->toBe('toBeGreaterThanOrEqual() requires an int or float subject. The subject type is string.');
    }

    #[Test]
    public function toBeLessThanPasses(): void
    {
        Expect::that(2)->because('toBeLessThan() passes')->toBeLessThan(3);
        Expect::that(-1.5)->because('toBeLessThan() passes')->toBeLessThan(0);
    }

    #[Test]
    public function toBeLessThanFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(3)->toBeLessThan(2),
        );

        Expect::that($detail->message)->because('toBeLessThan() fails')->toBe('Expected 3 to be less than 2.');
    }

    #[Test]
    public function notToBeLessThan(): void
    {
        Expect::that(3)->because('not()->toBe() less than')->not()->toBeLessThan(2);
    }

    #[Test]
    public function toBeLessThanGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(null)->toBeLessThan(2), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::that($detail->message)->because('toBeLessThan() guards the subject type')
            ->toBe('toBeLessThan() requires an int or float subject. The subject type is null.');
    }

    #[Test]
    public function toBeLessThanOrEqualPasses(): void
    {
        Expect::that(2)->because('toBeLessThanOrEqual() passes')->toBeLessThanOrEqual(3);
        Expect::that(3)->because('toBeLessThanOrEqual() passes')->toBeLessThanOrEqual(3);
        Expect::that(-1.5)->because('toBeLessThanOrEqual() passes')->toBeLessThanOrEqual(-1.5);
    }

    #[Test]
    public function toBeLessThanOrEqualFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(3)->toBeLessThanOrEqual(2),
        );

        Expect::that($detail->message)->because('toBeLessThanOrEqual() fails')->toBe('Expected 3 to be less than or equal to 2.');
        Expect::that($detail->expected)->because('toBeLessThanOrEqual() fails')->toBe('less than or equal to 2');
    }

    #[Test]
    public function notToBeLessThanOrEqual(): void
    {
        Expect::that(3)->because('not()->toBeLessThan() or equal')->not()->toBeLessThanOrEqual(2);
    }

    #[Test]
    public function toBeLessThanOrEqualGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(null)->toBeLessThanOrEqual(2), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::that($detail->message)->because('toBeLessThanOrEqual() guards the subject type')
            ->toBe('toBeLessThanOrEqual() requires an int or float subject. The subject type is null.');
    }

    #[Test]
    public function toBeWithinPasses(): void
    {
        Expect::that(1.05)->because('toBeWithin() passes')->toBeWithin(0.1, 1.0);
        Expect::that(0.95)->because('toBeWithin() passes')->toBeWithin(0.1, 1.0);
        Expect::that(3)->because('toBeWithin() passes')->toBeWithin(0.5, 3.0);
    }

    #[Test]
    #[DataSet('toleranceBoundaries')]
    public function toBeWithinIncludesTheToleranceBoundary(float $subject): void
    {
        Expect::that($subject)
            ->because('toBeWithin() MUST include the exact tolerance boundary')
            ->toBeWithin(0.1, 1.0);
    }

    #[Test]
    public function toBeWithinFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(1.5)->toBeWithin(0.1, 1.0),
        );

        Expect::that($detail->message)->because('toBeWithin() fails')->toBe('Expected 1.5 to be within 0.1 of 1.0.');
        Expect::that($detail->expected)->because('toBeWithin() fails')->toBe('within 0.1 of 1.0');
    }

    #[Test]
    public function notToBeWithin(): void
    {
        Expect::that(1.5)->because('not()->toBe() within')->not()->toBeWithin(0.1, 1.0);
    }

    #[Test]
    public function toBeWithinGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('1.0')->toBeWithin(0.1, 1.0), // @phpstan-ignore greenlight.nativeMatcher.subjectType (deliberately invalid: tests runtime validation)
        );

        Expect::that($detail->message)->because('toBeWithin() guards the subject type')
            ->toBe('toBeWithin() requires an int or float subject. The subject type is string.');
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function toleranceBoundaries(): iterable
    {
        yield 'lower boundary' => [0.9];
        yield 'upper boundary' => [1.1];
    }
}

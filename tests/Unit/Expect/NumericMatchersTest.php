<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class NumericMatchersTest
{
    #[Test]
    public function toBeGreaterThanPasses(): void
    {
        Expect::that(3)->toBeGreaterThan(2);
        Expect::that(2.5)->toBeGreaterThan(2);
    }

    #[Test]
    public function toBeGreaterThanFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(2)->toBeGreaterThan(3),
        );

        Expect::that($detail->message)->toBe('Expected 2 to be greater than 3.');
        Expect::that($detail->expected)->toBe('greater than 3');
    }

    #[Test]
    public function toBeGreaterThanIsStrict(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(3)->toBeGreaterThan(3),
        );

        Expect::that($detail->message)->toBe('Expected 3 to be greater than 3.');
    }

    #[Test]
    public function notToBeGreaterThan(): void
    {
        Expect::that(2)->not()->toBeGreaterThan(3);
        Expect::that(3)->not()->toBeGreaterThan(3);
    }

    #[Test]
    public function toBeGreaterThanGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('3')->toBeGreaterThan(2),
        );

        Expect::that($detail->message)->toBe('toBeGreaterThan() requires an int or float subject, got string.');
    }

    #[Test]
    public function toBeGreaterThanOrEqualPasses(): void
    {
        Expect::that(3)->toBeGreaterThanOrEqual(2);
        Expect::that(3)->toBeGreaterThanOrEqual(3);
        Expect::that(2.5)->toBeGreaterThanOrEqual(2.5);
    }

    #[Test]
    public function toBeGreaterThanOrEqualFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(2)->toBeGreaterThanOrEqual(3),
        );

        Expect::that($detail->message)->toBe('Expected 2 to be greater than or equal to 3.');
        Expect::that($detail->expected)->toBe('greater than or equal to 3');
    }

    #[Test]
    public function notToBeGreaterThanOrEqual(): void
    {
        Expect::that(2)->not()->toBeGreaterThanOrEqual(3);
    }

    #[Test]
    public function toBeGreaterThanOrEqualGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('3')->toBeGreaterThanOrEqual(2),
        );

        Expect::that($detail->message)->toBe('toBeGreaterThanOrEqual() requires an int or float subject, got string.');
    }

    #[Test]
    public function toBeLessThanPasses(): void
    {
        Expect::that(2)->toBeLessThan(3);
        Expect::that(-1.5)->toBeLessThan(0);
    }

    #[Test]
    public function toBeLessThanFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(3)->toBeLessThan(2),
        );

        Expect::that($detail->message)->toBe('Expected 3 to be less than 2.');
    }

    #[Test]
    public function notToBeLessThan(): void
    {
        Expect::that(3)->not()->toBeLessThan(2);
    }

    #[Test]
    public function toBeLessThanGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(null)->toBeLessThan(2),
        );

        Expect::that($detail->message)->toBe('toBeLessThan() requires an int or float subject, got null.');
    }

    #[Test]
    public function toBeLessThanOrEqualPasses(): void
    {
        Expect::that(2)->toBeLessThanOrEqual(3);
        Expect::that(3)->toBeLessThanOrEqual(3);
        Expect::that(-1.5)->toBeLessThanOrEqual(-1.5);
    }

    #[Test]
    public function toBeLessThanOrEqualFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(3)->toBeLessThanOrEqual(2),
        );

        Expect::that($detail->message)->toBe('Expected 3 to be less than or equal to 2.');
        Expect::that($detail->expected)->toBe('less than or equal to 2');
    }

    #[Test]
    public function notToBeLessThanOrEqual(): void
    {
        Expect::that(3)->not()->toBeLessThanOrEqual(2);
    }

    #[Test]
    public function toBeLessThanOrEqualGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(null)->toBeLessThanOrEqual(2),
        );

        Expect::that($detail->message)->toBe('toBeLessThanOrEqual() requires an int or float subject, got null.');
    }

    #[Test]
    public function toBeWithinPasses(): void
    {
        Expect::that(1.05)->toBeWithin(0.1, 1.0);
        Expect::that(0.95)->toBeWithin(0.1, 1.0);
        Expect::that(3)->toBeWithin(0.5, 3.0);
    }

    #[Test]
    public function toBeWithinFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(1.5)->toBeWithin(0.1, 1.0),
        );

        Expect::that($detail->message)->toBe('Expected 1.5 to be within 0.1 of 1.0.');
        Expect::that($detail->expected)->toBe('within 0.1 of 1.0');
    }

    #[Test]
    public function notToBeWithin(): void
    {
        Expect::that(1.5)->not()->toBeWithin(0.1, 1.0);
    }

    #[Test]
    public function toBeWithinGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that('1.0')->toBeWithin(0.1, 1.0),
        );

        Expect::that($detail->message)->toBe('toBeWithin() requires an int or float subject, got string.');
    }
}

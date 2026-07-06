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
        new Expect()->that(3)->toBeGreaterThan(2);
        new Expect()->that(2.5)->toBeGreaterThan(2);
    }

    #[Test]
    public function toBeGreaterThanFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(2)->toBeGreaterThan(3),
        );

        $expect = new Expect();
        $expect->that($detail->message)->toBe('Expected 2 to be greater than 3.');
        $expect->that($detail->expected)->toBe('greater than 3');
    }

    #[Test]
    public function toBeGreaterThanIsStrict(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(3)->toBeGreaterThan(3),
        );

        new Expect()->that($detail->message)->toBe('Expected 3 to be greater than 3.');
    }

    #[Test]
    public function notToBeGreaterThan(): void
    {
        new Expect()->that(2)->not()->toBeGreaterThan(3);
        new Expect()->that(3)->not()->toBeGreaterThan(3);
    }

    #[Test]
    public function toBeGreaterThanGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that('3')->toBeGreaterThan(2),
        );

        new Expect()->that($detail->message)->toBe('toBeGreaterThan() requires an int or float subject, got string.');
    }

    #[Test]
    public function toBeLessThanPasses(): void
    {
        new Expect()->that(2)->toBeLessThan(3);
        new Expect()->that(-1.5)->toBeLessThan(0);
    }

    #[Test]
    public function toBeLessThanFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(3)->toBeLessThan(2),
        );

        new Expect()->that($detail->message)->toBe('Expected 3 to be less than 2.');
    }

    #[Test]
    public function notToBeLessThan(): void
    {
        new Expect()->that(3)->not()->toBeLessThan(2);
    }

    #[Test]
    public function toBeLessThanGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(null)->toBeLessThan(2),
        );

        new Expect()->that($detail->message)->toBe('toBeLessThan() requires an int or float subject, got null.');
    }

    #[Test]
    public function toBeWithinPasses(): void
    {
        new Expect()->that(1.05)->toBeWithin(0.1, 1.0);
        new Expect()->that(0.95)->toBeWithin(0.1, 1.0);
        new Expect()->that(3)->toBeWithin(0.5, 3.0);
    }

    #[Test]
    public function toBeWithinFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(1.5)->toBeWithin(0.1, 1.0),
        );

        $expect = new Expect();
        $expect->that($detail->message)->toBe('Expected 1.5 to be within 0.1 of 1.0.');
        $expect->that($detail->expected)->toBe('within 0.1 of 1.0');
    }

    #[Test]
    public function notToBeWithin(): void
    {
        new Expect()->that(1.5)->not()->toBeWithin(0.1, 1.0);
    }

    #[Test]
    public function toBeWithinGuardsTheSubjectType(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that('1.0')->toBeWithin(0.1, 1.0),
        );

        new Expect()->that($detail->message)->toBe('toBeWithin() requires an int or float subject, got string.');
    }
}

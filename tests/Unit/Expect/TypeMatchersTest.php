<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class TypeMatchersTest
{
    #[Test]
    public function toBeInstanceOfPasses(): void
    {
        Expect::that(new \ArrayObject())->toBeInstanceOf(\ArrayObject::class);
        Expect::that(new \ArrayObject())->toBeInstanceOf(\Traversable::class);
    }

    #[Test]
    public function toBeInstanceOfFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(new \stdClass())->toBeInstanceOf(\ArrayObject::class),
        );

        Expect::that($detail->message)->toBe('Expected stdClass {} to be an instance of ArrayObject.');
        Expect::that($detail->expected)->toBe('ArrayObject');
    }

    #[Test]
    public function toBeInstanceOfFailsOnNonObjects(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(42)->toBeInstanceOf(\ArrayObject::class),
        );

        Expect::that($detail->message)->toBe('Expected 42 to be an instance of ArrayObject.');
    }

    #[Test]
    public function notToBeInstanceOf(): void
    {
        Expect::that(new \stdClass())->not()->toBeInstanceOf(\ArrayObject::class);
    }

    #[Test]
    public function toBeTruePasses(): void
    {
        Expect::that(true)->toBeTrue();
    }

    #[Test]
    public function toBeTrueFailsOnTruthyNonBooleans(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(1)->toBeTrue());

        Expect::that($detail->message)->toBe('Expected 1 to be true.');
    }

    #[Test]
    public function notToBeTrue(): void
    {
        Expect::that(false)->not()->toBeTrue();
        Expect::that('yes')->not()->toBeTrue();
    }

    #[Test]
    public function toBeFalsePasses(): void
    {
        Expect::that(false)->toBeFalse();
    }

    #[Test]
    public function toBeFalseFailsOnFalsyNonBooleans(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(0)->toBeFalse());

        Expect::that($detail->message)->toBe('Expected 0 to be false.');
    }

    #[Test]
    public function notToBeFalse(): void
    {
        Expect::that(true)->not()->toBeFalse();
    }

    #[Test]
    public function toBeNullPasses(): void
    {
        Expect::that(null)->toBeNull();
    }

    #[Test]
    public function toBeNullFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that('')->toBeNull());

        Expect::that($detail->message)->toBe("Expected '' to be null.");
    }

    #[Test]
    public function notToBeNull(): void
    {
        Expect::that(0)->not()->toBeNull();
    }
}

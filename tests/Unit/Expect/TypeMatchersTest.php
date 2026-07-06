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
        new Expect()->that(new \ArrayObject())->toBeInstanceOf(\ArrayObject::class);
        new Expect()->that(new \ArrayObject())->toBeInstanceOf(\Traversable::class);
    }

    #[Test]
    public function toBeInstanceOfFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(new \stdClass())->toBeInstanceOf(\ArrayObject::class),
        );

        $expect = new Expect();
        $expect->that($detail->message)->toBe('Expected stdClass {} to be an instance of ArrayObject.');
        $expect->that($detail->expected)->toBe('ArrayObject');
    }

    #[Test]
    public function toBeInstanceOfFailsOnNonObjects(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(42)->toBeInstanceOf(\ArrayObject::class),
        );

        new Expect()->that($detail->message)->toBe('Expected 42 to be an instance of ArrayObject.');
    }

    #[Test]
    public function notToBeInstanceOf(): void
    {
        new Expect()->that(new \stdClass())->not()->toBeInstanceOf(\ArrayObject::class);
    }

    #[Test]
    public function toBeTruePasses(): void
    {
        new Expect()->that(true)->toBeTrue();
    }

    #[Test]
    public function toBeTrueFailsOnTruthyNonBooleans(): void
    {
        $detail = FailureProbe::detailOf(static fn() => new Expect()->that(1)->toBeTrue());

        new Expect()->that($detail->message)->toBe('Expected 1 to be true.');
    }

    #[Test]
    public function notToBeTrue(): void
    {
        new Expect()->that(false)->not()->toBeTrue();
        new Expect()->that('yes')->not()->toBeTrue();
    }

    #[Test]
    public function toBeFalsePasses(): void
    {
        new Expect()->that(false)->toBeFalse();
    }

    #[Test]
    public function toBeFalseFailsOnFalsyNonBooleans(): void
    {
        $detail = FailureProbe::detailOf(static fn() => new Expect()->that(0)->toBeFalse());

        new Expect()->that($detail->message)->toBe('Expected 0 to be false.');
    }

    #[Test]
    public function notToBeFalse(): void
    {
        new Expect()->that(true)->not()->toBeFalse();
    }

    #[Test]
    public function toBeNullPasses(): void
    {
        new Expect()->that(null)->toBeNull();
    }

    #[Test]
    public function toBeNullFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => new Expect()->that('')->toBeNull());

        new Expect()->that($detail->message)->toBe("Expected '' to be null.");
    }

    #[Test]
    public function notToBeNull(): void
    {
        new Expect()->that(0)->not()->toBeNull();
    }
}

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

    #[Test]
    public function toBeArrayPasses(): void
    {
        Expect::that([])->toBeArray();
        Expect::that(['a' => 1])->toBeArray();
    }

    #[Test]
    public function toBeArrayFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that('a')->toBeArray());

        Expect::that($detail->message)->toBe('Expected string to be an array.');
        Expect::that($detail->expected)->toBe('array');
    }

    #[Test]
    public function notToBeArray(): void
    {
        Expect::that('a')->not()->toBeArray();
    }

    #[Test]
    public function toBeStringPasses(): void
    {
        Expect::that('')->toBeString();
        Expect::that('a')->toBeString();
    }

    #[Test]
    public function toBeStringFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(1)->toBeString());

        Expect::that($detail->message)->toBe('Expected int to be a string.');
        Expect::that($detail->expected)->toBe('string');
    }

    #[Test]
    public function notToBeString(): void
    {
        Expect::that(1)->not()->toBeString();
    }

    #[Test]
    public function toBeIntPasses(): void
    {
        Expect::that(0)->toBeInt();
        Expect::that(-5)->toBeInt();
    }

    #[Test]
    public function toBeIntFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that('1')->toBeInt());

        Expect::that($detail->message)->toBe('Expected string to be an int.');
        Expect::that($detail->expected)->toBe('int');
    }

    #[Test]
    public function notToBeInt(): void
    {
        Expect::that(1.0)->not()->toBeInt();
    }

    #[Test]
    public function toBeFloatPasses(): void
    {
        Expect::that(1.5)->toBeFloat();
        Expect::that(\NAN)->toBeFloat();
    }

    #[Test]
    public function toBeFloatFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(1)->toBeFloat());

        Expect::that($detail->message)->toBe('Expected int to be a float.');
        Expect::that($detail->expected)->toBe('float');
    }

    #[Test]
    public function notToBeFloat(): void
    {
        Expect::that(1)->not()->toBeFloat();
    }

    #[Test]
    public function toBeBoolPasses(): void
    {
        Expect::that(true)->toBeBool();
        Expect::that(false)->toBeBool();
    }

    #[Test]
    public function toBeBoolFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(0)->toBeBool());

        Expect::that($detail->message)->toBe('Expected int to be a bool.');
        Expect::that($detail->expected)->toBe('bool');
    }

    #[Test]
    public function notToBeBool(): void
    {
        Expect::that(0)->not()->toBeBool();
    }

    #[Test]
    public function toBeCallablePasses(): void
    {
        Expect::that(static fn() => null)->toBeCallable();
        Expect::that('strlen')->toBeCallable();
    }

    #[Test]
    public function toBeCallableFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(42)->toBeCallable());

        Expect::that($detail->message)->toBe('Expected int to be callable.');
        Expect::that($detail->expected)->toBe('callable');
    }

    #[Test]
    public function notToBeCallable(): void
    {
        Expect::that(42)->not()->toBeCallable();
    }

    #[Test]
    public function toBeIterablePasses(): void
    {
        Expect::that([])->toBeIterable();
        Expect::that(new \ArrayObject())->toBeIterable();
    }

    #[Test]
    public function toBeIterableFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that('abc')->toBeIterable());

        Expect::that($detail->message)->toBe('Expected string to be iterable.');
        Expect::that($detail->expected)->toBe('iterable');
    }

    #[Test]
    public function notToBeIterable(): void
    {
        Expect::that('abc')->not()->toBeIterable();
    }
}

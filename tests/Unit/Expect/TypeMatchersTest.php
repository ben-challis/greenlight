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
        Expect::that(new \ArrayObject())->because('toBeInstanceOf() passes')->toBeInstanceOf(\ArrayObject::class);
        Expect::that(new \ArrayObject())->because('toBeInstanceOf() passes')->toBeInstanceOf(\Traversable::class);
    }

    #[Test]
    public function toBeInstanceOfFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(new \stdClass())->toBeInstanceOf(\ArrayObject::class),
        );

        Expect::that($detail->message)->because('toBeInstanceOf() fails')->toBe('Expected stdClass {} to be an instance of ArrayObject.');
        Expect::that($detail->expected)->because('toBeInstanceOf() fails')->toBe('ArrayObject');
    }

    #[Test]
    public function toBeInstanceOfFailsOnNonObjects(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(42)->toBeInstanceOf(\ArrayObject::class),
        );

        Expect::that($detail->message)->because('toBeInstanceOf() fails on non objects')->toBe('Expected 42 to be an instance of ArrayObject.');
    }

    #[Test]
    public function notToBeInstanceOf(): void
    {
        Expect::that(new \stdClass())->because('not()->toBe() instance of')->not()->toBeInstanceOf(\ArrayObject::class);
    }

    #[Test]
    public function toBeTruePasses(): void
    {
        Expect::that(true)->because('toBeTrue() passes')->toBeTrue();
    }

    #[Test]
    public function toBeTrueFailsOnTruthyNonBooleans(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(1)->toBeTrue());

        Expect::that($detail->message)->because('toBeTrue() fails on truthy non booleans')->toBe('Expected 1 to be true.');
    }

    #[Test]
    public function notToBeTrue(): void
    {
        Expect::that(false)->because('not()->toBe() true')->not()->toBeTrue();
        Expect::that('yes')->because('not()->toBe() true')->not()->toBeTrue();
    }

    #[Test]
    public function toBeFalsePasses(): void
    {
        Expect::that(false)->because('toBeFalse() passes')->toBeFalse();
    }

    #[Test]
    public function toBeFalseFailsOnFalsyNonBooleans(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(0)->toBeFalse());

        Expect::that($detail->message)->because('toBeFalse() fails on falsy non booleans')->toBe('Expected 0 to be false.');
    }

    #[Test]
    public function notToBeFalse(): void
    {
        Expect::that(true)->because('not()->toBe() false')->not()->toBeFalse();
    }

    #[Test]
    public function toBeNullPasses(): void
    {
        Expect::that(null)->because('toBeNull() passes')->toBeNull();
    }

    #[Test]
    public function toBeNullFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that('')->toBeNull());

        Expect::that($detail->message)->because('toBeNull() fails')->toBe("Expected '' to be null.");
    }

    #[Test]
    public function notToBeNull(): void
    {
        Expect::that(0)->because('not()->toBe() null')->not()->toBeNull();
    }

    #[Test]
    public function toBeArrayPasses(): void
    {
        Expect::that([])->because('toBeArray() passes')->toBeArray();
        Expect::that(['a' => 1])->because('toBeArray() passes')->toBeArray();
    }

    #[Test]
    public function toBeArrayFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that('a')->toBeArray());

        Expect::that($detail->message)->because('toBeArray() fails')->toBe('Expected string to be an array.');
        Expect::that($detail->expected)->because('toBeArray() fails')->toBe('array');
    }

    #[Test]
    public function notToBeArray(): void
    {
        Expect::that('a')->because('not()->toBe() array')->not()->toBeArray();
    }

    #[Test]
    public function toBeStringPasses(): void
    {
        Expect::that('')->because('toBeString() passes')->toBeString();
        Expect::that('a')->because('toBeString() passes')->toBeString();
    }

    #[Test]
    public function toBeStringFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(1)->toBeString());

        Expect::that($detail->message)->because('toBeString() fails')->toBe('Expected int to be a string.');
        Expect::that($detail->expected)->because('toBeString() fails')->toBe('string');
    }

    #[Test]
    public function notToBeString(): void
    {
        Expect::that(1)->because('not()->toBe() string')->not()->toBeString();
    }

    #[Test]
    public function toBeIntPasses(): void
    {
        Expect::that(0)->because('toBeInt() passes')->toBeInt();
        Expect::that(-5)->because('toBeInt() passes')->toBeInt();
    }

    #[Test]
    public function toBeIntFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that('1')->toBeInt());

        Expect::that($detail->message)->because('toBeInt() fails')->toBe('Expected string to be an int.');
        Expect::that($detail->expected)->because('toBeInt() fails')->toBe('int');
    }

    #[Test]
    public function notToBeInt(): void
    {
        Expect::that(1.0)->because('not()->toBe() int')->not()->toBeInt();
    }

    #[Test]
    public function toBeFloatPasses(): void
    {
        Expect::that(1.5)->because('toBeFloat() passes')->toBeFloat();
        Expect::that(\NAN)->because('toBeFloat() passes')->toBeFloat();
    }

    #[Test]
    public function toBeFloatFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(1)->toBeFloat());

        Expect::that($detail->message)->because('toBeFloat() fails')->toBe('Expected int to be a float.');
        Expect::that($detail->expected)->because('toBeFloat() fails')->toBe('float');
    }

    #[Test]
    public function notToBeFloat(): void
    {
        Expect::that(1)->because('not()->toBe() float')->not()->toBeFloat();
    }

    #[Test]
    public function toBeBoolPasses(): void
    {
        Expect::that(true)->because('toBeBool() passes')->toBeBool();
        Expect::that(false)->because('toBeBool() passes')->toBeBool();
    }

    #[Test]
    public function toBeBoolFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(0)->toBeBool());

        Expect::that($detail->message)->because('toBeBool() fails')->toBe('Expected int to be a bool.');
        Expect::that($detail->expected)->because('toBeBool() fails')->toBe('bool');
    }

    #[Test]
    public function notToBeBool(): void
    {
        Expect::that(0)->because('not()->toBe() bool')->not()->toBeBool();
    }

    #[Test]
    public function toBeCallablePasses(): void
    {
        Expect::that(static fn() => null)->because('toBeCallable() passes')->toBeCallable();
        Expect::that('strlen')->because('toBeCallable() passes')->toBeCallable();
    }

    #[Test]
    public function toBeCallableFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(42)->toBeCallable());

        Expect::that($detail->message)->because('toBeCallable() fails')->toBe('Expected int to be callable.');
        Expect::that($detail->expected)->because('toBeCallable() fails')->toBe('callable');
    }

    #[Test]
    public function notToBeCallable(): void
    {
        Expect::that(42)->because('not()->toBe() callable')->not()->toBeCallable();
    }

    #[Test]
    public function toBeIterablePasses(): void
    {
        Expect::that([])->because('toBeIterable() passes')->toBeIterable();
        Expect::that(new \ArrayObject())->because('toBeIterable() passes')->toBeIterable();
    }

    #[Test]
    public function toBeIterableFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that('abc')->toBeIterable());

        Expect::that($detail->message)->because('toBeIterable() fails')->toBe('Expected string to be iterable.');
        Expect::that($detail->expected)->because('toBeIterable() fails')->toBe('iterable');
    }

    #[Test]
    public function notToBeIterable(): void
    {
        Expect::that('abc')->because('not()->toBe() iterable')->not()->toBeIterable();
    }
}

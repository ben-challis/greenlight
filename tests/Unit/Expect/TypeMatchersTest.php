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
        Expect::value(new \ArrayObject())->because('toBeInstanceOf() passes')->toBeInstanceOf(\ArrayObject::class);
        Expect::value(new \ArrayObject())->because('toBeInstanceOf() passes')->toBeInstanceOf(\Traversable::class);
    }

    #[Test]
    public function toBeInstanceOfFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value(new \stdClass())->toBeInstanceOf(\ArrayObject::class),
        );

        Expect::value($detail->message)->because('toBeInstanceOf() fails')->toBe('Expected stdClass {} to be an instance of ArrayObject.');
        Expect::value($detail->expected)->because('toBeInstanceOf() fails')->toBe('ArrayObject');
    }

    #[Test]
    public function toBeInstanceOfFailsOnNonObjects(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value(42)->toBeInstanceOf(\ArrayObject::class),
        );

        Expect::value($detail->message)->because('toBeInstanceOf() fails on non objects')->toBe('Expected 42 to be an instance of ArrayObject.');
    }

    #[Test]
    public function notToBeInstanceOf(): void
    {
        Expect::value(new \stdClass())->because('not()->toBe() instance of')->not()->toBeInstanceOf(\ArrayObject::class);
    }

    #[Test]
    public function toBeTruePasses(): void
    {
        Expect::value(true)->because('toBeTrue() passes')->toBeTrue();
    }

    #[Test]
    public function toBeTrueFailsOnTruthyNonBooleans(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::value(1)->toBeTrue());

        Expect::value($detail->message)->because('toBeTrue() fails on truthy non booleans')->toBe('Expected 1 to be true.');
    }

    #[Test]
    public function notToBeTrue(): void
    {
        Expect::value(false)->because('not()->toBe() true')->not()->toBeTrue();
        Expect::value('yes')->because('not()->toBe() true')->not()->toBeTrue();
    }

    #[Test]
    public function toBeFalsePasses(): void
    {
        Expect::value(false)->because('toBeFalse() passes')->toBeFalse();
    }

    #[Test]
    public function toBeFalseFailsOnFalsyNonBooleans(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::value(0)->toBeFalse());

        Expect::value($detail->message)->because('toBeFalse() fails on falsy non booleans')->toBe('Expected 0 to be false.');
    }

    #[Test]
    public function notToBeFalse(): void
    {
        Expect::value(true)->because('not()->toBe() false')->not()->toBeFalse();
    }

    #[Test]
    public function toBeNullPasses(): void
    {
        Expect::value(null)->because('toBeNull() passes')->toBeNull();
    }

    #[Test]
    public function toBeNullFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::value('')->toBeNull());

        Expect::value($detail->message)->because('toBeNull() fails')->toBe("Expected '' to be null.");
    }

    #[Test]
    public function notToBeNull(): void
    {
        Expect::value(0)->because('not()->toBe() null')->not()->toBeNull();
    }

    #[Test]
    public function toBeArrayPasses(): void
    {
        Expect::value([])->because('toBeArray() passes')->toBeArray();
        Expect::value(['a' => 1])->because('toBeArray() passes')->toBeArray();
    }

    #[Test]
    public function toBeArrayFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::value('a')->toBeArray());

        Expect::value($detail->message)->because('toBeArray() fails')->toBe('Expected string to be an array.');
        Expect::value($detail->expected)->because('toBeArray() fails')->toBe('array');
    }

    #[Test]
    public function notToBeArray(): void
    {
        Expect::value('a')->because('not()->toBe() array')->not()->toBeArray();
    }

    #[Test]
    public function toBeStringPasses(): void
    {
        Expect::value('')->because('toBeString() passes')->toBeString();
        Expect::value('a')->because('toBeString() passes')->toBeString();
    }

    #[Test]
    public function toBeStringFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::value(1)->toBeString());

        Expect::value($detail->message)->because('toBeString() fails')->toBe('Expected int to be a string.');
        Expect::value($detail->expected)->because('toBeString() fails')->toBe('string');
    }

    #[Test]
    public function notToBeString(): void
    {
        Expect::value(1)->because('not()->toBe() string')->not()->toBeString();
    }

    #[Test]
    public function toBeIntPasses(): void
    {
        Expect::value(0)->because('toBeInt() passes')->toBeInt();
        Expect::value(-5)->because('toBeInt() passes')->toBeInt();
    }

    #[Test]
    public function toBeIntFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::value('1')->toBeInt());

        Expect::value($detail->message)->because('toBeInt() fails')->toBe('Expected string to be an int.');
        Expect::value($detail->expected)->because('toBeInt() fails')->toBe('int');
    }

    #[Test]
    public function notToBeInt(): void
    {
        Expect::value(1.0)->because('not()->toBe() int')->not()->toBeInt();
    }

    #[Test]
    public function toBeFloatPasses(): void
    {
        Expect::value(1.5)->because('toBeFloat() passes')->toBeFloat();
        Expect::value(\NAN)->because('toBeFloat() passes')->toBeFloat();
    }

    #[Test]
    public function toBeFloatFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::value(1)->toBeFloat());

        Expect::value($detail->message)->because('toBeFloat() fails')->toBe('Expected int to be a float.');
        Expect::value($detail->expected)->because('toBeFloat() fails')->toBe('float');
    }

    #[Test]
    public function notToBeFloat(): void
    {
        Expect::value(1)->because('not()->toBe() float')->not()->toBeFloat();
    }

    #[Test]
    public function toBeBoolPasses(): void
    {
        Expect::value(true)->because('toBeBool() passes')->toBeBool();
        Expect::value(false)->because('toBeBool() passes')->toBeBool();
    }

    #[Test]
    public function toBeBoolFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::value(0)->toBeBool());

        Expect::value($detail->message)->because('toBeBool() fails')->toBe('Expected int to be a bool.');
        Expect::value($detail->expected)->because('toBeBool() fails')->toBe('bool');
    }

    #[Test]
    public function notToBeBool(): void
    {
        Expect::value(0)->because('not()->toBe() bool')->not()->toBeBool();
    }

    #[Test]
    public function toBeCallablePasses(): void
    {
        Expect::value(static fn() => null)->because('toBeCallable() passes')->toBeCallable();
        Expect::value('strlen')->because('toBeCallable() passes')->toBeCallable();
    }

    #[Test]
    public function toBeCallableFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::value(42)->toBeCallable());

        Expect::value($detail->message)->because('toBeCallable() fails')->toBe('Expected int to be callable.');
        Expect::value($detail->expected)->because('toBeCallable() fails')->toBe('callable');
    }

    #[Test]
    public function notToBeCallable(): void
    {
        Expect::value(42)->because('not()->toBe() callable')->not()->toBeCallable();
    }

    #[Test]
    public function toBeIterablePasses(): void
    {
        Expect::value([])->because('toBeIterable() passes')->toBeIterable();
        Expect::value(new \ArrayObject())->because('toBeIterable() passes')->toBeIterable();
    }

    #[Test]
    public function toBeIterableFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::value('abc')->toBeIterable());

        Expect::value($detail->message)->because('toBeIterable() fails')->toBe('Expected string to be iterable.');
        Expect::value($detail->expected)->because('toBeIterable() fails')->toBe('iterable');
    }

    #[Test]
    public function notToBeIterable(): void
    {
        Expect::value('abc')->because('not()->toBe() iterable')->not()->toBeIterable();
    }
}

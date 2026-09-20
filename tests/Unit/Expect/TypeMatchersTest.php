<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;

use function Greenlight\expect;

final class TypeMatchersTest
{
    #[Test]
    public function toBeInstanceOfPasses(): void
    {
        expect(new \ArrayObject())
            ->because('toBeInstanceOf() passes')
            ->toBeInstanceOf(\ArrayObject::class)
            ->because('toBeInstanceOf() passes')
            ->toBeInstanceOf(\Traversable::class);
    }

    #[Test]
    public function toBeInstanceOfFails(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(new \stdClass())->toBeInstanceOf(\ArrayObject::class),
        );

        expect($detail->message)->because('toBeInstanceOf() fails')->toBe('Expected stdClass {} to be an instance of ArrayObject.');
        expect($detail->expected)->because('toBeInstanceOf() fails')->toBe('ArrayObject');
    }

    #[Test]
    public function toBeInstanceOfFailsOnNonObjects(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(42)->toBeInstanceOf(\ArrayObject::class),
        );

        expect($detail->message)->because('toBeInstanceOf() fails on non objects')->toBe('Expected 42 to be an instance of ArrayObject.');
    }

    #[Test]
    public function notToBeInstanceOf(): void
    {
        expect(new \stdClass())->because('not()->toBe() instance of')->not()->toBeInstanceOf(\ArrayObject::class);
    }

    #[Test]
    public function toBeTruePasses(): void
    {
        expect(true)->because('toBeTrue() passes')->toBeTrue();
    }

    #[Test]
    public function toBeTrueFailsOnTruthyNonBooleans(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect(1)->toBeTrue());

        expect($detail->message)->because('toBeTrue() fails on truthy non booleans')->toBe('Expected 1 to be true.');
    }

    #[Test]
    public function notToBeTrue(): void
    {
        expect(false)->because('not()->toBe() true')->not()->toBeTrue();
        expect('yes')->because('not()->toBe() true')->not()->toBeTrue();
    }

    #[Test]
    public function toBeFalsePasses(): void
    {
        expect(false)->because('toBeFalse() passes')->toBeFalse();
    }

    #[Test]
    public function toBeFalseFailsOnFalsyNonBooleans(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect(0)->toBeFalse());

        expect($detail->message)->because('toBeFalse() fails on falsy non booleans')->toBe('Expected 0 to be false.');
    }

    #[Test]
    public function notToBeFalse(): void
    {
        expect(true)->because('not()->toBe() false')->not()->toBeFalse();
    }

    #[Test]
    public function toBeNullPasses(): void
    {
        expect(null)->because('toBeNull() passes')->toBeNull();
    }

    #[Test]
    public function toBeNullFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect('')->toBeNull());

        expect($detail->message)->because('toBeNull() fails')->toBe("Expected '' to be null.");
    }

    #[Test]
    public function notToBeNull(): void
    {
        expect(0)->because('not()->toBe() null')->not()->toBeNull();
    }

    #[Test]
    public function toBeArrayPasses(): void
    {
        expect([])->because('toBeArray() passes')->toBeArray();
        expect(['a' => 1])->because('toBeArray() passes')->toBeArray();
    }

    #[Test]
    public function toBeArrayFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect('a')->toBeArray());

        expect($detail->message)->because('toBeArray() fails')->toBe('Expected string to be an array.');
        expect($detail->expected)->because('toBeArray() fails')->toBe('array');
    }

    #[Test]
    public function notToBeArray(): void
    {
        expect('a')->because('not()->toBe() array')->not()->toBeArray();
    }

    #[Test]
    public function toBeStringPasses(): void
    {
        expect('')->because('toBeString() passes')->toBeString();
        expect('a')->because('toBeString() passes')->toBeString();
    }

    #[Test]
    public function toBeStringFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect(1)->toBeString());

        expect($detail->message)->because('toBeString() fails')->toBe('Expected int to be a string.');
        expect($detail->expected)->because('toBeString() fails')->toBe('string');
    }

    #[Test]
    public function notToBeString(): void
    {
        expect(1)->because('not()->toBe() string')->not()->toBeString();
    }

    #[Test]
    public function toBeIntPasses(): void
    {
        expect(0)->because('toBeInt() passes')->toBeInt();
        expect(-5)->because('toBeInt() passes')->toBeInt();
    }

    #[Test]
    public function toBeIntFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect('1')->toBeInt());

        expect($detail->message)->because('toBeInt() fails')->toBe('Expected string to be an int.');
        expect($detail->expected)->because('toBeInt() fails')->toBe('int');
    }

    #[Test]
    public function notToBeInt(): void
    {
        expect(1.0)->because('not()->toBe() int')->not()->toBeInt();
    }

    #[Test]
    public function toBeFloatPasses(): void
    {
        expect(1.5)->because('toBeFloat() passes')->toBeFloat();
        expect(\NAN)->because('toBeFloat() passes')->toBeFloat();
    }

    #[Test]
    public function toBeFloatFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect(1)->toBeFloat());

        expect($detail->message)->because('toBeFloat() fails')->toBe('Expected int to be a float.');
        expect($detail->expected)->because('toBeFloat() fails')->toBe('float');
    }

    #[Test]
    public function notToBeFloat(): void
    {
        expect(1)->because('not()->toBe() float')->not()->toBeFloat();
    }

    #[Test]
    public function toBeBoolPasses(): void
    {
        expect(true)->because('toBeBool() passes')->toBeBool();
        expect(false)->because('toBeBool() passes')->toBeBool();
    }

    #[Test]
    public function toBeBoolFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect(0)->toBeBool());

        expect($detail->message)->because('toBeBool() fails')->toBe('Expected int to be a bool.');
        expect($detail->expected)->because('toBeBool() fails')->toBe('bool');
    }

    #[Test]
    public function notToBeBool(): void
    {
        expect(0)->because('not()->toBe() bool')->not()->toBeBool();
    }

    #[Test]
    public function toBeCallablePasses(): void
    {
        expect(static fn() => null)->because('toBeCallable() passes')->toBeCallable();
        expect('strlen')->because('toBeCallable() passes')->toBeCallable();
    }

    #[Test]
    public function toBeCallableFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect(42)->toBeCallable());

        expect($detail->message)->because('toBeCallable() fails')->toBe('Expected int to be callable.');
        expect($detail->expected)->because('toBeCallable() fails')->toBe('callable');
    }

    #[Test]
    public function notToBeCallable(): void
    {
        expect(42)->because('not()->toBe() callable')->not()->toBeCallable();
    }

    #[Test]
    public function toBeIterablePasses(): void
    {
        expect([])->because('toBeIterable() passes')->toBeIterable();
        expect(new \ArrayObject())->because('toBeIterable() passes')->toBeIterable();
    }

    #[Test]
    public function toBeIterableFails(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect('abc')->toBeIterable());

        expect($detail->message)->because('toBeIterable() fails')->toBe('Expected string to be iterable.');
        expect($detail->expected)->because('toBeIterable() fails')->toBe('iterable');
    }

    #[Test]
    public function notToBeIterable(): void
    {
        expect('abc')->because('not()->toBe() iterable')->not()->toBeIterable();
    }
}

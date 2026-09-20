<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Tests\Fixture\Expect\Node;
use Greenlight\Tests\Fixture\Expect\Point;
use Greenlight\Tests\Fixture\Expect\Suit;

use function Greenlight\expect;

final class ToBeAndToEqualTest
{
    #[Test]
    public function toBePassesOnIdentity(): void
    {
        $object = new \stdClass();

        expect(3)->because('toBe() passes on identity')->toBe(3);
        expect('a')->because('toBe() passes on identity')->toBe('a');
        expect($object)->because('toBe() passes on identity')->toBe($object);
        expect(null)->because('toBe() passes on identity')->toBe(null);
    }

    #[Test]
    public function toBeFailsWithRenderedMessage(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect(3)->toBe(4));

        expect($detail->message)->because('toBe() fails with rendered message')->toBe('Expected 3 to be 4.');
        expect($detail->expected)->because('toBe() fails with rendered message')->toBe('4');
        expect($detail->actual)->because('toBe() fails with rendered message')->toBe('3');
    }

    #[Test]
    public function toBeRequiresIdentityNotLooseEquality(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect('1')->toBe(1));

        expect($detail->message)->because('toBe() requires identity not loose equality')->toBe("Expected '1' to be 1.");
    }

    #[Test]
    public function notToBePassesOnDifferentValues(): void
    {
        expect(3)->because('not()->toBe() passes on different values')->not()->toBe(4);
        expect(new \stdClass())->because('not()->toBe() passes on different values')->not()->toBe(new \stdClass());
    }

    #[Test]
    public function notToBeFailsWithNegatedMessage(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect(3)->not()->toBe(3));

        expect($detail->message)->because('not()->toBe() fails with negated message')->toBe('Expected 3 not to be 3.');
        expect($detail->expected)->because('not()->toBe() fails with negated message')->toBe('not 3');
    }

    #[Test]
    public function toEqualComparesNumbersByValue(): void
    {
        expect(1)->because('toEqual() compares numbers by value')->toEqual(1.0);
        expect(1.5)->because('toEqual() compares numbers by value')->toEqual(1.5);
        expect(\NAN)->because('toEqual() compares numbers by value')->not()->toEqual(\NAN);
    }

    #[Test]
    public function toEqualDoesNotRoundLargeIntegers(): void
    {
        $integer = 9_007_199_254_740_993;
        $roundedFloat = (float) $integer;

        expect($integer)
            ->because('toEqual() keeps integer precision')
            ->not()
            ->toEqual($roundedFloat);
        expect($roundedFloat)
            ->because('toEqual() keeps integer precision in both operand orders')
            ->not()
            ->toEqual($integer);
    }

    #[Test]
    public function toEqualKeepsOtherScalarsStrict(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect('1')->toEqual(1));

        expect($detail->message)->because('toEqual() keeps other scalars strict')->toBe("Expected '1' to equal 1.");
        expect(true)->because('toEqual() keeps other scalars strict')->not()->toEqual(1);
    }

    #[Test]
    public function toEqualIgnoresArrayKeyOrder(): void
    {
        expect(['b' => 2, 'a' => ['x' => 1.0]])->because('toEqual() ignores array key order')->toEqual(['a' => ['x' => 1], 'b' => 2]);
        expect([1, 2])->because('toEqual() ignores array key order')->not()->toEqual([2, 1]);
    }

    #[Test]
    public function toEqualComparesObjectsByClassAndProperties(): void
    {
        expect(new Point(1, 2))
            ->because('toEqual() compares objects by class and properties')
            ->toEqual(new Point(1, 2))
            ->not()
            ->toEqual(new Point(1, 3))
            ->not()
            ->toEqual(new \stdClass());
    }

    #[Test]
    public function toEqualRejectsObjectsWithDifferentPropertyCounts(): void
    {
        $withProperty = new \stdClass();
        $withProperty->value = 1;

        expect($withProperty)
            ->because('object equality requires the same property count')
            ->not()
            ->toEqual(new \stdClass());
    }

    #[Test]
    public function toEqualPreservesPropertyNamesWithNullValues(): void
    {
        $subject = (object) ['first' => null, 'second' => 2];

        expect($subject)
            ->toEqual((object) ['second' => 2.0, 'first' => null])
            ->not()
            ->toEqual((object) ['other' => null, 'second' => 2]);
    }

    #[Test]
    public function toEqualComparesEnumsByIdentity(): void
    {
        expect(Suit::Hearts)
            ->because('toEqual() compares enums by identity')
            ->toEqual(Suit::Hearts)
            ->not()
            ->toEqual(Suit::Spades);
    }

    #[Test]
    public function toEqualComparesDateTimesByInstant(): void
    {
        $utc = new \DateTimeImmutable('2024-01-01T12:00:00+00:00');
        $cet = new \DateTimeImmutable('2024-01-01T13:00:00+01:00');

        expect($utc)
            ->because('toEqual() compares date times by instant')
            ->toEqual($cet)
            ->not()
            ->toEqual(new \DateTimeImmutable('2024-01-01T12:00:01+00:00'));
    }

    #[Test]
    public function toEqualDistinguishesDateTimesOneMicrosecondApart(): void
    {
        $instant = new \DateTimeImmutable('2024-01-01T12:00:00.123456+00:00');
        $nextMicrosecond = new \DateTimeImmutable('2024-01-01T12:00:00.123457+00:00');

        expect($instant)
            ->because('date time equality MUST preserve microsecond precision')
            ->not()
            ->toEqual($nextMicrosecond);
    }

    #[Test]
    public function toEqualTerminatesOnCyclicStructures(): void
    {
        $first = new Node();
        $first->next = $first;
        $second = new Node();
        $second->next = $second;

        expect($first)->because('toEqual() terminates on cyclic structures')->toEqual($second);
    }

    #[Test]
    public function toEqualFailureRendersBothSides(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => expect(['a' => 1])->toEqual(['a' => 2]),
        );

        expect($detail->message)->because('toEqual() failure renders both sides')->toBe("Expected ['a' => 1] to equal ['a' => 2].");
        expect($detail->expected)->because('toEqual() failure renders both sides')->toBe("['a' => 2]");
        expect($detail->actual)->because('toEqual() failure renders both sides')->toBe("['a' => 1]");
    }
}

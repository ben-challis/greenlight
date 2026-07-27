<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Expect\Node;
use Greenlight\Tests\Fixture\Expect\Point;
use Greenlight\Tests\Fixture\Expect\Suit;

final class ToBeAndToEqualTest
{
    #[Test]
    public function toBePassesOnIdentity(): void
    {
        $object = new \stdClass();

        Expect::that(3)->because('toBe() passes on identity')->toBe(3);
        Expect::that('a')->because('toBe() passes on identity')->toBe('a');
        Expect::that($object)->because('toBe() passes on identity')->toBe($object);
        Expect::that(null)->because('toBe() passes on identity')->toBe(null);
    }

    #[Test]
    public function toBeFailsWithRenderedMessage(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(3)->toBe(4));

        Expect::that($detail->message)->because('toBe() fails with rendered message')->toBe('Expected 3 to be 4.');
        Expect::that($detail->expected)->because('toBe() fails with rendered message')->toBe('4');
        Expect::that($detail->actual)->because('toBe() fails with rendered message')->toBe('3');
    }

    #[Test]
    public function toBeRequiresIdentityNotLooseEquality(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that('1')->toBe(1));

        Expect::that($detail->message)->because('toBe() requires identity not loose equality')->toBe("Expected '1' to be 1.");
    }

    #[Test]
    public function notToBePassesOnDifferentValues(): void
    {
        Expect::that(3)->because('not()->toBe() passes on different values')->not()->toBe(4);
        Expect::that(new \stdClass())->because('not()->toBe() passes on different values')->not()->toBe(new \stdClass());
    }

    #[Test]
    public function notToBeFailsWithNegatedMessage(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(3)->not()->toBe(3));

        Expect::that($detail->message)->because('not()->toBe() fails with negated message')->toBe('Expected 3 not to be 3.');
        Expect::that($detail->expected)->because('not()->toBe() fails with negated message')->toBe('not 3');
    }

    #[Test]
    public function toEqualComparesNumbersByValue(): void
    {
        Expect::that(1)->because('toEqual() compares numbers by value')->toEqual(1.0);
        Expect::that(1.5)->because('toEqual() compares numbers by value')->toEqual(1.5);
        Expect::that(\NAN)->because('toEqual() compares numbers by value')->not()->toEqual(\NAN);
    }

    #[Test]
    public function toEqualKeepsOtherScalarsStrict(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that('1')->toEqual(1));

        Expect::that($detail->message)->because('toEqual() keeps other scalars strict')->toBe("Expected '1' to equal 1.");
        Expect::that(true)->because('toEqual() keeps other scalars strict')->not()->toEqual(1);
    }

    #[Test]
    public function toEqualIgnoresArrayKeyOrder(): void
    {
        Expect::that(['b' => 2, 'a' => ['x' => 1.0]])->because('toEqual() ignores array key order')->toEqual(['a' => ['x' => 1], 'b' => 2]);
        Expect::that([1, 2])->because('toEqual() ignores array key order')->not()->toEqual([2, 1]);
    }

    #[Test]
    public function toEqualComparesObjectsByClassAndProperties(): void
    {
        Expect::that(new Point(1, 2))->because('toEqual() compares objects by class and properties')->toEqual(new Point(1, 2));
        Expect::that(new Point(1, 2))->because('toEqual() compares objects by class and properties')->not()->toEqual(new Point(1, 3));
        Expect::that(new Point(1, 2))->because('toEqual() compares objects by class and properties')->not()->toEqual(new \stdClass());
    }

    #[Test]
    public function toEqualComparesEnumsByIdentity(): void
    {
        Expect::that(Suit::Hearts)->because('toEqual() compares enums by identity')->toEqual(Suit::Hearts);
        Expect::that(Suit::Hearts)->because('toEqual() compares enums by identity')->not()->toEqual(Suit::Spades);
    }

    #[Test]
    public function toEqualComparesDateTimesByInstant(): void
    {
        $utc = new \DateTimeImmutable('2024-01-01T12:00:00+00:00');
        $cet = new \DateTimeImmutable('2024-01-01T13:00:00+01:00');

        Expect::that($utc)->because('toEqual() compares date times by instant')->toEqual($cet);
        Expect::that($utc)->because('toEqual() compares date times by instant')->not()->toEqual(new \DateTimeImmutable('2024-01-01T12:00:01+00:00'));
    }

    #[Test]
    public function toEqualTerminatesOnCyclicStructures(): void
    {
        $first = new Node();
        $first->next = $first;
        $second = new Node();
        $second->next = $second;

        Expect::that($first)->because('toEqual() terminates on cyclic structures')->toEqual($second);
    }

    #[Test]
    public function toEqualFailureRendersBothSides(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(['a' => 1])->toEqual(['a' => 2]),
        );

        Expect::that($detail->message)->because('toEqual() failure renders both sides')->toBe("Expected ['a' => 1] to equal ['a' => 2].");
        Expect::that($detail->expected)->because('toEqual() failure renders both sides')->toBe("['a' => 2]");
        Expect::that($detail->actual)->because('toEqual() failure renders both sides')->toBe("['a' => 1]");
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class ToBeAndToEqualTest
{
    #[Test]
    public function toBePassesOnIdentity(): void
    {
        $object = new \stdClass();

        Expect::that(3)->toBe(3);
        Expect::that('a')->toBe('a');
        Expect::that($object)->toBe($object);
        Expect::that(null)->toBe(null);
    }

    #[Test]
    public function toBeFailsWithRenderedMessage(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(3)->toBe(4));

        Expect::that($detail->message)->toBe('Expected 3 to be 4.');
        Expect::that($detail->expected)->toBe('4');
        Expect::that($detail->actual)->toBe('3');
    }

    #[Test]
    public function toBeRequiresIdentityNotLooseEquality(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that('1')->toBe(1));

        Expect::that($detail->message)->toBe("Expected '1' to be 1.");
    }

    #[Test]
    public function notToBePassesOnDifferentValues(): void
    {
        Expect::that(3)->not()->toBe(4);
        Expect::that(new \stdClass())->not()->toBe(new \stdClass());
    }

    #[Test]
    public function notToBeFailsWithNegatedMessage(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that(3)->not()->toBe(3));

        Expect::that($detail->message)->toBe('Expected 3 not to be 3.');
        Expect::that($detail->expected)->toBe('not 3');
    }

    #[Test]
    public function toEqualComparesNumbersByValue(): void
    {
        Expect::that(1)->toEqual(1.0);
        Expect::that(1.5)->toEqual(1.5);
        Expect::that(\NAN)->not()->toEqual(\NAN);
    }

    #[Test]
    public function toEqualKeepsOtherScalarsStrict(): void
    {
        $detail = FailureProbe::detailOf(static fn() => Expect::that('1')->toEqual(1));

        Expect::that($detail->message)->toBe("Expected '1' to equal 1.");
        Expect::that(true)->not()->toEqual(1);
    }

    #[Test]
    public function toEqualIgnoresArrayKeyOrder(): void
    {
        Expect::that(['b' => 2, 'a' => ['x' => 1.0]])->toEqual(['a' => ['x' => 1], 'b' => 2]);
        Expect::that([1, 2])->not()->toEqual([2, 1]);
    }

    #[Test]
    public function toEqualComparesObjectsByClassAndProperties(): void
    {
        Expect::that(new Point(1, 2))->toEqual(new Point(1, 2));
        Expect::that(new Point(1, 2))->not()->toEqual(new Point(1, 3));
        Expect::that(new Point(1, 2))->not()->toEqual(new \stdClass());
    }

    #[Test]
    public function toEqualComparesEnumsByIdentity(): void
    {
        Expect::that(Suit::Hearts)->toEqual(Suit::Hearts);
        Expect::that(Suit::Hearts)->not()->toEqual(Suit::Spades);
    }

    #[Test]
    public function toEqualComparesDateTimesByInstant(): void
    {
        $utc = new \DateTimeImmutable('2024-01-01T12:00:00+00:00');
        $cet = new \DateTimeImmutable('2024-01-01T13:00:00+01:00');

        Expect::that($utc)->toEqual($cet);
        Expect::that($utc)->not()->toEqual(new \DateTimeImmutable('2024-01-01T12:00:01+00:00'));
    }

    #[Test]
    public function toEqualTerminatesOnCyclicStructures(): void
    {
        $first = new Node();
        $first->next = $first;
        $second = new Node();
        $second->next = $second;

        Expect::that($first)->toEqual($second);
    }

    #[Test]
    public function toEqualFailureRendersBothSides(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::that(['a' => 1])->toEqual(['a' => 2]),
        );

        Expect::that($detail->message)->toBe("Expected ['a' => 1] to equal ['a' => 2].");
        Expect::that($detail->expected)->toBe("['a' => 2]");
        Expect::that($detail->actual)->toBe("['a' => 1]");
    }
}

final class Point
{
    public function __construct(
        public int $x,
        private readonly int $y,
    ) {}

    public function y(): int
    {
        return $this->y;
    }
}

enum Suit
{
    case Hearts;
    case Spades;
}

final class Node
{
    public ?Node $next = null;
}

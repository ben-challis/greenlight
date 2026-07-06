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

        new Expect()->that(3)->toBe(3);
        new Expect()->that('a')->toBe('a');
        new Expect()->that($object)->toBe($object);
        new Expect()->that(null)->toBe(null);
    }

    #[Test]
    public function toBeFailsWithRenderedMessage(): void
    {
        $detail = FailureProbe::detailOf(static fn() => new Expect()->that(3)->toBe(4));

        $expect = new Expect();
        $expect->that($detail->message)->toBe('Expected 3 to be 4.');
        $expect->that($detail->expected)->toBe('4');
        $expect->that($detail->actual)->toBe('3');
    }

    #[Test]
    public function toBeRequiresIdentityNotLooseEquality(): void
    {
        $detail = FailureProbe::detailOf(static fn() => new Expect()->that('1')->toBe(1));

        new Expect()->that($detail->message)->toBe("Expected '1' to be 1.");
    }

    #[Test]
    public function notToBePassesOnDifferentValues(): void
    {
        new Expect()->that(3)->not()->toBe(4);
        new Expect()->that(new \stdClass())->not()->toBe(new \stdClass());
    }

    #[Test]
    public function notToBeFailsWithNegatedMessage(): void
    {
        $detail = FailureProbe::detailOf(static fn() => new Expect()->that(3)->not()->toBe(3));

        $expect = new Expect();
        $expect->that($detail->message)->toBe('Expected 3 not to be 3.');
        $expect->that($detail->expected)->toBe('not 3');
    }

    #[Test]
    public function toEqualComparesNumbersByValue(): void
    {
        new Expect()->that(1)->toEqual(1.0);
        new Expect()->that(1.5)->toEqual(1.5);
        new Expect()->that(\NAN)->not()->toEqual(\NAN);
    }

    #[Test]
    public function toEqualKeepsOtherScalarsStrict(): void
    {
        $detail = FailureProbe::detailOf(static fn() => new Expect()->that('1')->toEqual(1));

        new Expect()->that($detail->message)->toBe("Expected '1' to equal 1.");
        new Expect()->that(true)->not()->toEqual(1);
    }

    #[Test]
    public function toEqualIgnoresArrayKeyOrder(): void
    {
        new Expect()->that(['b' => 2, 'a' => ['x' => 1.0]])->toEqual(['a' => ['x' => 1], 'b' => 2]);
        new Expect()->that([1, 2])->not()->toEqual([2, 1]);
    }

    #[Test]
    public function toEqualComparesObjectsByClassAndProperties(): void
    {
        new Expect()->that(new Point(1, 2))->toEqual(new Point(1, 2));
        new Expect()->that(new Point(1, 2))->not()->toEqual(new Point(1, 3));
        new Expect()->that(new Point(1, 2))->not()->toEqual(new \stdClass());
    }

    #[Test]
    public function toEqualComparesEnumsByIdentity(): void
    {
        new Expect()->that(Suit::Hearts)->toEqual(Suit::Hearts);
        new Expect()->that(Suit::Hearts)->not()->toEqual(Suit::Spades);
    }

    #[Test]
    public function toEqualComparesDateTimesByInstant(): void
    {
        $utc = new \DateTimeImmutable('2024-01-01T12:00:00+00:00');
        $cet = new \DateTimeImmutable('2024-01-01T13:00:00+01:00');

        new Expect()->that($utc)->toEqual($cet);
        new Expect()->that($utc)->not()->toEqual(new \DateTimeImmutable('2024-01-01T12:00:01+00:00'));
    }

    #[Test]
    public function toEqualTerminatesOnCyclicStructures(): void
    {
        $first = new Node();
        $first->next = $first;
        $second = new Node();
        $second->next = $second;

        new Expect()->that($first)->toEqual($second);
    }

    #[Test]
    public function toEqualFailureRendersBothSides(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(['a' => 1])->toEqual(['a' => 2]),
        );

        $expect = new Expect();
        $expect->that($detail->message)->toBe("Expected ['a' => 1] to equal ['a' => 2].");
        $expect->that($detail->expected)->toBe("['a' => 2]");
        $expect->that($detail->actual)->toBe("['a' => 1]");
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

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final class ToThrowTest
{
    #[Test]
    public function toThrowPassesOnMatchingClass(): void
    {
        new Expect()
            ->that(static fn() => throw new \DomainException('insufficient funds'))
            ->toThrow(\DomainException::class);
    }

    #[Test]
    public function toThrowPassesOnSubclassesAndMessagePattern(): void
    {
        new Expect()
            ->that(static fn() => throw new \DomainException('insufficient funds'))
            ->toThrow(\LogicException::class, matching: '/insufficient funds/');
    }

    #[Test]
    public function toThrowFailsWhenNothingIsThrown(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(static fn(): int => 1)->toThrow(\DomainException::class),
        );

        $expect = new Expect();
        $expect->that($detail->message)->toBe('Expected a callable that threw nothing to throw DomainException.');
        $expect->that($detail->expected)->toBe('DomainException');
        $expect->that($detail->actual)->toBe('a callable that threw nothing');
    }

    #[Test]
    public function toThrowFailsOnTheWrongClass(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()
                ->that(static fn() => throw new \RuntimeException('boom'))
                ->toThrow(\DomainException::class),
        );

        new Expect()->that($detail->message)->toBe(
            "Expected a callable that threw RuntimeException with message 'boom' to throw DomainException.",
        );
    }

    #[Test]
    public function toThrowFailsOnAMessageMismatch(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()
                ->that(static fn() => throw new \DomainException('boom'))
                ->toThrow(\DomainException::class, matching: '/insufficient funds/'),
        );

        new Expect()->that($detail->message)->toBe(
            "Expected a callable that threw DomainException with message 'boom' "
            . 'to throw DomainException with message matching /insufficient funds/.',
        );
    }

    #[Test]
    public function notToThrowPassesWhenNothingIsThrown(): void
    {
        new Expect()->that(static fn(): int => 1)->not()->toThrow(\DomainException::class);
    }

    #[Test]
    public function notToThrowPassesWhenADifferentThrowableIsThrown(): void
    {
        new Expect()
            ->that(static fn() => throw new \RuntimeException('boom'))
            ->not()->toThrow(\DomainException::class);
    }

    #[Test]
    public function notToThrowFailsWhenTheThrowableMatches(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()
                ->that(static fn() => throw new \DomainException('boom'))
                ->not()->toThrow(\DomainException::class),
        );

        new Expect()->that($detail->message)->toBe(
            "Expected a callable that threw DomainException with message 'boom' not to throw DomainException.",
        );
    }

    #[Test]
    public function toThrowGuardsTheSubjectTypeEvenWhenNegated(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => new Expect()->that(42)->not()->toThrow(\DomainException::class),
        );

        new Expect()->that($detail->message)->toBe('toThrow() requires a callable subject, got int.');
    }

    #[Test]
    public function toThrowRejectsInvalidPatternsBeforeInvokingTheSubject(): void
    {
        $invoked = false;

        new Expect()
            ->that(function () use (&$invoked): void {
                new Expect()
                    ->that(static function () use (&$invoked): void {
                        $invoked = true;
                    })
                    ->toThrow(\DomainException::class, matching: 'not a pattern');
            })
            ->toThrow(\InvalidArgumentException::class, matching: '/invalid regular expression/');

        new Expect()->that($invoked)->toBeFalse();
    }
}

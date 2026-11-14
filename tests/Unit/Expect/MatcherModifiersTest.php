<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\MatcherModifiers;

use function Greenlight\expect;

final class MatcherModifiersTest
{
    #[Test]
    public function handOffMovesTheNegationAndCopiesTheReason(): void
    {
        $modifiers = new MatcherModifiers();
        $modifiers->negate();
        $modifiers->because('  a reason  ');

        $next = $modifiers->handOff();

        expect($next->isNegated())->because('the next stage MUST receive the pending negation')->toBeTrue();
        expect($next->reason())->because('the next stage MUST receive the trimmed reason')->toBe('a reason');
        expect($modifiers->isNegated())->because('a negation applies to one stage only')->toBeFalse();
        expect($modifiers->reason())->because('the reason stays for later matchers')->toBe('a reason');
    }

    #[Test]
    public function passOnCallsTheStageMethods(): void
    {
        $modifiers = new MatcherModifiers();
        $modifiers->negate();
        $modifiers->because('why');
        $calls = [];

        $modifiers->passOn(
            static function () use (&$calls): void {
                $calls[] = 'not';
            },
            static function (string $reason) use (&$calls): void {
                $calls[] = 'because ' . $reason;
            },
        );
        $modifiers->passOn(
            static function () use (&$calls): void {
                $calls[] = 'not';
            },
            static function (string $reason) use (&$calls): void {
                $calls[] = 'because ' . $reason;
            },
        );

        expect($calls)
            ->because('only the first stage receives the negation')
            ->toBe(['not', 'because why', 'because why']);
    }

    #[Test]
    public function anEmptyReasonIsAUsageFailure(): void
    {
        $detail = FailureProbe::detailOf(static fn() => new MatcherModifiers()->because(' ', static fn(): string => 'subject'));

        expect($detail->message)->because('an empty reason is a usage failure')->toBe('because() requires a non-empty reason.');
        expect($detail->actual)->because('the usage failure keeps the rendered subject')->toBe('subject');
    }
}

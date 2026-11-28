<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ValueRenderer;

use function Greenlight\expect;

final class FailureMessageDifferenceTest
{
    #[Test]
    public function floatsRenderWithFullPrecision(): void
    {
        $renderer = new ValueRenderer();

        expect($renderer->render(0.1 + 0.2))
            ->because('a float MUST render with the precision that identifies it')
            ->toBe('0.30000000000000004');
        expect($renderer->render(1e100))->because('an exponent keeps a float marker')->toBe('1.0E+100');
        expect($renderer->render(-2.5e-7))->because('small floats keep their digits')->toBe('-2.5E-7');
        expect($renderer->render(30.0))->because('whole floats use fixed notation')->toBe('30.0');
        expect($renderer->render(100.0))->because('whole floats use fixed notation')->toBe('100.0');
        expect($renderer->render(1500.0))->because('whole floats use fixed notation')->toBe('1500.0');
        expect($renderer->render(0.0001))->because('small floats use fixed notation')->toBe('0.0001');
        expect($renderer->render(1e17))->because('large floats use exponent notation')->toBe('1.0E+17');
        expect($renderer->render(1e-5))->because('tiny floats use exponent notation')->toBe('1.0E-5');
        expect($renderer->render(-0.0))->because('negative zero keeps its sign')->toBe('-0.0');

        $detail = FailureProbe::detailOf(static fn() => expect(0.1 + 0.2)->toBe(0.3));

        expect($detail->message)
            ->because('expected and actual floats MUST render differently')
            ->toBe('Expected 0.30000000000000004 to be 0.3.');
    }

    #[Test]
    public function nonAsciiWhitespaceIsEscaped(): void
    {
        $renderer = new ValueRenderer();

        expect($renderer->render("a\u{00A0}b"))
            ->because('a no-break space MUST NOT render as a space')
            ->toBe("'a\\u{00A0}b'");
        expect($renderer->render("a\u{200B}b"))
            ->because('a zero-width space MUST be visible')
            ->toBe("'a\\u{200B}b'");
        expect($renderer->render('a b'))->because('the ASCII space stays a space')->toBe("'a b'");
    }

    #[Test]
    public function arrayDifferenceAfterTheItemLimitShowsItsPath(): void
    {
        $actual = \range(0, 20);
        $expected = $actual;
        $expected[15] = 99;

        $detail = FailureProbe::detailOf(static fn() => expect($actual)->toEqual($expected));

        expect($detail->message)
            ->because('a difference that the item limit hides MUST be located by its path')
            ->toEndWith('(first difference at [15]: actual 15, expected 99).');
    }

    #[Test]
    public function nestedObjectDifferenceShowsItsPath(): void
    {
        $actual = ['a' => (object) ['b' => [[[1]]]]];
        $expected = ['a' => (object) ['b' => [[[2]]]]];

        $detail = FailureProbe::detailOf(static fn() => expect($actual)->toEqual($expected));

        expect($detail->message)
            ->because('a difference that the depth limit hides MUST be located by its path')
            ->toEndWith("(first difference at ['a']->b[0][0][0]: actual 1, expected 2).");
    }

    #[Test]
    public function missingKeyIsNamed(): void
    {
        $actual = \range(0, 20);
        $expected = $actual;
        unset($expected[20]);
        $expected[21] = 20;

        $detail = FailureProbe::detailOf(static fn() => expect($actual)->toBe($expected));

        expect($detail->message)
            ->because('a key that only one value has MUST be named')
            ->toEndWith('(first difference at [20]: actual 20, expected (missing)).');
    }

    #[Test]
    public function stringDifferenceAfterTheCharacterLimitShowsAWindow(): void
    {
        $actual = \str_repeat('a', 300);
        $expected = $actual;
        $expected[200] = 'b';

        $detail = FailureProbe::detailOf(static fn() => expect($actual)->toBe($expected));

        expect($detail->message)
            ->because('a difference that the character limit hides MUST be shown in a window')
            ->toContain('first difference at offset 200: actual \'...' . \str_repeat('a', 30))
            ->toContain(\str_repeat('a', 30) . 'b' . \str_repeat('a', 49) . "...'");
    }

    #[Test]
    public function visibleDifferencesKeepTheShortMessage(): void
    {
        $detail = FailureProbe::detailOf(static fn() => expect([1, 2])->toEqual([1, 3]));

        expect($detail->message)
            ->because('a visible difference needs no extra description')
            ->toBe('Expected [1, 2] to equal [1, 3].');
    }

    #[Test]
    public function largeNestedValuesHaveABoundedMessage(): void
    {
        $actual = \array_fill(0, 10, \array_fill(0, 10, \array_fill(0, 10, \str_repeat('x', 200))));
        $expected = $actual;
        $expected[9][9][9] = 'y';

        $detail = FailureProbe::detailOf(static fn() => expect($actual)->toEqual($expected));

        expect(\strlen($detail->message))
            ->because('each rendered value MUST have a total byte limit')
            ->toBeLessThan(6000);
        expect($detail->message)
            ->because('the bounded message MUST still locate the difference')
            ->toContain("first difference at [9][9][9], offset 0: actual 'xxx");
    }
}

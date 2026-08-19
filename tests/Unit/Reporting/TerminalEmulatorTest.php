<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\TerminalEmulator;

final class TerminalEmulatorTest
{
    #[Test]
    public function plainTextAccumulatesLineByLine(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("first\nsecond\n");

        Expect::that($terminal->visibleLines())->because('plain text accumulates line by line')->toBe(['first', 'second', '']);
    }

    #[Test]
    public function carriageReturnRewritesTheCurrentLineFromColumnZero(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("wrong\rright");

        Expect::that($terminal->visibleLines())->because('carriage return rewrites the current line from column zero')->toBe(['right']);
    }

    #[Test]
    public function cursorUpMovesTheWriteHeadWithoutTouchingContent(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("top\nbottom\n\x1b[2A\rreplaced");

        Expect::that($terminal->visibleLines())->because('cursor up moves the write head without touching content')->toBe(['replaced', 'bottom', '']);
    }

    #[Test]
    public function zeroCursorUpDistanceUsesTheDefaultOneRow(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("top\nbottom\x1b[0A\rreplaced");

        Expect::that($terminal->visibleLines())
            ->because('a zero cursor-up distance MUST have the same effect as the default distance')
            ->toBe(['replaced', 'bottom']);
    }

    #[Test]
    public function clearLineErasesOnlyTheCurrentRow(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("keep\nlose\n\x1b[1A\r\x1b[2Krewritten");

        Expect::that($terminal->visibleLines())->because('clear line erases only the current row')->toBe(['keep', 'rewritten', '']);
    }

    #[Test]
    public function eraseToEndOfScreenDropsCurrentTailAndLaterRows(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("keep\nalso keep\ngone\n\x1b[2A\rgo\x1b[0J");

        Expect::that($terminal->visibleLines())->because('erase to end of screen drops current tail and later rows')->toBe(['keep', 'go']);
    }

    #[Test]
    public function sgrColorCodesAreStrippedByDefault(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("\x1b[32mok\x1b[0m plain");

        Expect::that($terminal->visibleLines())->because('SGR color codes are stripped by default')->toBe(['ok plain']);
    }

    #[Test]
    public function sgrColorCodesCanBeRetainedOnRequest(): void
    {
        $terminal = new TerminalEmulator(retainColor: true);
        $terminal->write("\x1b[32mok\x1b[0m");

        Expect::that($terminal->screen())->because('SGR color codes can be retained on request')->toBe("\x1b[32mok\x1b[0m");
    }

    #[Test]
    public function cursorVisibilityTogglesWithoutAffectingTheGrid(): void
    {
        $terminal = new TerminalEmulator();

        Expect::that($terminal->isCursorHidden())->because('cursor visibility toggles without affecting the grid')->toBeFalse();

        $terminal->write("\x1b[?25lhidden");

        Expect::that($terminal->isCursorHidden())->because('cursor visibility toggles without affecting the grid')->toBeTrue();
        Expect::that($terminal->visibleLines())->toBe(['hidden']);

        $terminal->write("\x1b[?25h");

        Expect::that($terminal->isCursorHidden())->because('cursor visibility toggles without affecting the grid')->toBeFalse();
    }

    #[Test]
    public function screenJoinsVisibleLinesWithNewlines(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("one\ntwo");

        Expect::that($terminal->screen())->because('screen joins visible lines with newlines')->toBe("one\ntwo");
    }

    #[Test]
    public function unrecognizedEscapeSequencesThrow(): void
    {
        Expect::that(static function (): void {
            new TerminalEmulator()->write("\x1b[5B");
        })->because('unrecognized escape sequences throw')->toThrow(\RuntimeException::class, '/Unrecognized escape sequence/');
    }

    #[Test]
    public function unterminatedEscapeBytesThrow(): void
    {
        Expect::that(static function (): void {
            new TerminalEmulator()->write("plain\x1b");
        })->because('unterminated escape bytes throw')->toThrow(\RuntimeException::class, '/Unrecognized escape sequence/');
    }
}

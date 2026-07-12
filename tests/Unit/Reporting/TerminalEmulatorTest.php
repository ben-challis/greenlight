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

        Expect::that($terminal->visibleLines())->toBe(['first', 'second', '']);
    }

    #[Test]
    public function carriageReturnRewritesTheCurrentLineFromColumnZero(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("wrong\rright");

        Expect::that($terminal->visibleLines())->toBe(['right']);
    }

    #[Test]
    public function cursorUpMovesTheWriteHeadWithoutTouchingContent(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("top\nbottom\n\x1b[2A\rreplaced");

        Expect::that($terminal->visibleLines())->toBe(['replaced', 'bottom', '']);
    }

    #[Test]
    public function clearLineErasesOnlyTheCurrentRow(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("keep\nlose\n\x1b[1A\r\x1b[2Krewritten");

        Expect::that($terminal->visibleLines())->toBe(['keep', 'rewritten', '']);
    }

    #[Test]
    public function eraseToEndOfScreenDropsCurrentTailAndLaterRows(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("keep\nalso keep\ngone\n\x1b[2A\rgo\x1b[0J");

        Expect::that($terminal->visibleLines())->toBe(['keep', 'go']);
    }

    #[Test]
    public function sgrColourCodesAreStrippedByDefault(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("\x1b[32mok\x1b[0m plain");

        Expect::that($terminal->visibleLines())->toBe(['ok plain']);
    }

    #[Test]
    public function sgrColourCodesCanBeRetainedOnRequest(): void
    {
        $terminal = new TerminalEmulator(retainColour: true);
        $terminal->write("\x1b[32mok\x1b[0m");

        Expect::that($terminal->screen())->toBe("\x1b[32mok\x1b[0m");
    }

    #[Test]
    public function cursorVisibilityTogglesWithoutAffectingTheGrid(): void
    {
        $terminal = new TerminalEmulator();

        Expect::that($terminal->isCursorHidden())->toBeFalse();

        $terminal->write("\x1b[?25lhidden");

        Expect::that($terminal->isCursorHidden())->toBeTrue()
            ->and($terminal->visibleLines())->toBe(['hidden']);

        $terminal->write("\x1b[?25h");

        Expect::that($terminal->isCursorHidden())->toBeFalse();
    }

    #[Test]
    public function screenJoinsVisibleLinesWithNewlines(): void
    {
        $terminal = new TerminalEmulator();
        $terminal->write("one\ntwo");

        Expect::that($terminal->screen())->toBe("one\ntwo");
    }

    #[Test]
    public function unrecognizedEscapeSequencesThrow(): void
    {
        Expect::that(static function (): void {
            new TerminalEmulator()->write("\x1b[5B");
        })->toThrow(\RuntimeException::class, '/Unrecognized escape sequence/');
    }

    #[Test]
    public function unterminatedEscapeBytesThrow(): void
    {
        Expect::that(static function (): void {
            new TerminalEmulator()->write("plain\x1b");
        })->toThrow(\RuntimeException::class, '/Unrecognized escape sequence/');
    }
}

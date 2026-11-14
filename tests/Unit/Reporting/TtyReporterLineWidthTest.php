<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestClassStarted;
use Greenlight\Reporting\TtyReporter;
use Greenlight\Tests\Support\TerminalEmulator;

use function Greenlight\expect;

final class TtyReporterLineWidthTest
{
    private const string LONG_CLASS = 'App\Tests\Integration\Payments\Settlement\LongNamedSettlementReconciliationTest';

    #[Test]
    public function liveLinesFitInsideTheTerminalWidth(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: true, terminalColumns: 40);

        $reporter->onEvent(new RunStarted('run-1', 2, 1, 1.0));
        $reporter->onEvent(new TestClassStarted(self::LONG_CLASS, 1.0));

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());
        $widths = \array_map(
            static fn(string $line): int => (int) \preg_match_all('/./su', $line),
            $terminal->visibleLines(),
        );

        expect(\max([0, ...$widths]))
            ->because('a live line wider than the terminal wraps and leaves a stale row after the next redraw')
            ->toBe(39);
        expect($terminal->screen())->toContain('  App\Tests\Integration\Payments\Settl…');
    }

    #[Test]
    public function colorSequencesDoNotCountTowardTheWidth(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: true, cursor: true, terminalColumns: 40);

        $reporter->onEvent(new RunStarted('run-1', 2, 1, 1.0));
        $reporter->onEvent(new TestClassStarted(self::LONG_CLASS, 1.0));

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());

        expect($terminal->screen())
            ->because('the truncated line keeps 39 visible columns although it contains color sequences')
            ->toContain('  App\Tests\Integration\Payments\Settl…');
        expect($output->buffer())
            ->because('a truncated colored line resets the color')
            ->toContain("Settl…\x1b[0m\n");
    }

    #[Test]
    public function shortLinesAreUnchanged(): void
    {
        $output = new BufferOutput();
        $reporter = new TtyReporter($output, color: false, cursor: true, terminalColumns: 40);

        $reporter->onEvent(new RunStarted('run-1', 2, 1, 1.0));
        $reporter->onEvent(new TestClassStarted('App\ShortTest', 1.0));

        $terminal = new TerminalEmulator();
        $terminal->write($output->buffer());

        expect($terminal->screen())->toContain('  App\ShortTest (0) 0.000s')->not()->toContain('…');
    }
}

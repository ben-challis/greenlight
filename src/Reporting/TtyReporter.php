<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Reporting\Output\Output;

/**
 * Terminal renderer: dots grouped per class with class names, coloured
 * outcomes, failure details after the run, a slowest-tests list, a memory
 * summary, and a seed line when the run was randomized. ANSI colouring is
 * toggled by the constructor; capability detection belongs to the caller.
 * Deliberately bounded: no animations, no themes, no cursor movement.
 *
 * @internal
 */
final class TtyReporter implements Reporter
{
    private const int SLOWEST_COUNT = 5;

    private const string GREEN = '32';
    private const string RED = '31';
    private const string YELLOW = '33';

    /**
     * @var list<TestResult>
     */
    private array $problems = [];

    /**
     * @var list<TestResult>
     */
    private array $slowest = [];

    private int $totalMemoryDelta = 0;

    private int $peakMemoryDelta = 0;

    private ?RunFinished $runFinished = null;

    public function __construct(
        private readonly Output $output,
        private readonly bool $ansi,
        private readonly ?int $seed = null,
    ) {}

    #[\Override]
    public function onEvent(Event $event): void
    {
        if ($event instanceof RunStarted) {
            $this->output->write(\sprintf("Running %d tests on %d workers\n\n", $event->plannedTests, $event->workers));

            return;
        }

        if ($event instanceof TestClassStarted) {
            $this->output->write($event->class . ' ');

            return;
        }

        if ($event instanceof TestClassFinished) {
            $this->output->write("\n");

            return;
        }

        if ($event instanceof TestFinished) {
            $this->onTestFinished($event->result);

            return;
        }

        if ($event instanceof RunFinished) {
            $this->runFinished = $event;
        }
    }

    #[\Override]
    public function finish(): void
    {
        foreach ($this->problems as $problem) {
            $heading = \sprintf('%s %s', ProblemDetails::outcomeLabel($problem), $problem->id);

            $this->output->write(\sprintf("\n%s\n%s", $this->colour(self::RED, $heading), ProblemDetails::render($problem)));
        }

        $this->writeSlowest();
        $this->writeMemory();

        if ($this->seed !== null) {
            $this->output->write(\sprintf("Seed: %d\n", $this->seed));
        }

        $this->writeSummary();
    }

    private function onTestFinished(TestResult $result): void
    {
        $this->output->write(match ($result->outcome) {
            Outcome::Passed => $this->colour(self::GREEN, '.'),
            Outcome::Failed => $this->colour(self::RED, 'F'),
            Outcome::Errored => $this->colour(self::RED, 'E'),
            Outcome::Skipped => $this->colour(self::YELLOW, 'S'),
        });

        if (!$result->outcome->isSuccessful()) {
            $this->problems[] = $result;
        }

        $this->totalMemoryDelta += $result->memoryDeltaBytes;
        $this->peakMemoryDelta = \max($this->peakMemoryDelta, $result->memoryDeltaBytes);

        $this->slowest[] = $result;
        \usort(
            $this->slowest,
            static fn(TestResult $a, TestResult $b): int => $b->durationSeconds <=> $a->durationSeconds,
        );
        $this->slowest = \array_slice($this->slowest, 0, self::SLOWEST_COUNT);
    }

    private function writeSlowest(): void
    {
        if ($this->slowest === []) {
            return;
        }

        $this->output->write("\nSlowest tests:\n");

        foreach ($this->slowest as $result) {
            $this->output->write(\sprintf("  %.3fs %s\n", $result->durationSeconds, $result->id));
        }
    }

    private function writeMemory(): void
    {
        $this->output->write(\sprintf(
            "\nMemory: %s total delta, %s peak test delta\n",
            $this->formatBytes($this->totalMemoryDelta),
            $this->formatBytes($this->peakMemoryDelta),
        ));
    }

    private function writeSummary(): void
    {
        $finished = $this->runFinished;

        if (!$finished instanceof RunFinished) {
            return;
        }

        $summary = $finished->summary;

        $line = \sprintf(
            'Tests: %d, Passed: %d, Failed: %d, Errored: %d, Skipped: %d (%.3fs)',
            $summary->total(),
            $summary->passed,
            $summary->failed,
            $summary->errored,
            $summary->skipped,
            $finished->durationSeconds,
        );

        $this->output->write("\n" . $this->colour($summary->isSuccessful() ? self::GREEN : self::RED, $line) . "\n");
    }

    private function colour(string $code, string $text): string
    {
        if (!$this->ansi) {
            return $text;
        }

        return "\e[" . $code . 'm' . $text . "\e[0m";
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return \sprintf('%.1f KB', $bytes / 1024);
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return \sprintf('%.1f MB', $bytes / (1024 * 1024));
        }

        return \sprintf('%.1f GB', $bytes / (1024 * 1024 * 1024));
    }
}

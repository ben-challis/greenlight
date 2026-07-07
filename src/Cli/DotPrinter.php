<?php

declare(strict_types=1);

namespace Greenlight\Cli;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Runner\Worker\EventSink;

/**
 * Minimal placeholder output until real reporters exist: a dot stream while
 * running, failure details afterwards.
 *
 * @internal
 */
final class DotPrinter implements EventSink
{
    /**
     * @var list<TestResult>
     */
    private array $problems = [];

    /**
     * @param \Closure(string): void $out
     */
    public function __construct(
        private readonly \Closure $out,
    ) {}

    #[\Override]
    public function emit(Event $event): void
    {
        if (!$event instanceof TestFinished) {
            return;
        }

        $result = $event->result;

        ($this->out)(match ($result->outcome) {
            Outcome::Passed => '.',
            Outcome::Failed => 'F',
            Outcome::Errored => 'E',
            Outcome::Skipped => 'S',
        });

        if (!$result->outcome->isSuccessful()) {
            $this->problems[] = $result;
        }
    }

    public function finish(): void
    {
        ($this->out)("\n");

        foreach ($this->problems as $result) {
            ($this->out)(\sprintf("\n%s %s\n", \strtoupper($result->outcome->value), $result->id));

            foreach ($result->failures as $failure) {
                ($this->out)('  ' . $failure->message . "\n");

                if ($failure->expected !== null) {
                    ($this->out)('  expected: ' . $failure->expected . "\n");
                }

                if ($failure->actual !== null) {
                    ($this->out)('  actual:   ' . $failure->actual . "\n");
                }

                if ($failure->location !== null) {
                    ($this->out)('  at ' . $failure->location . "\n");
                }
            }

            $error = $result->error;

            if ($error !== null) {
                ($this->out)(\sprintf("  %s: %s\n  at %s:%d\n", $error->class, $error->message, $error->file, $error->line));
            }

            if ($result->attempts > 1) {
                ($this->out)(\sprintf("  after %d attempts\n", $result->attempts));
            }

            $captured = $result->output;

            if ($captured !== null && $captured->stdout !== '') {
                ($this->out)("  captured output:\n");

                foreach (\explode("\n", \rtrim($captured->stdout, "\n")) as $line) {
                    ($this->out)('    ' . $line . "\n");
                }
            }
        }
    }
}

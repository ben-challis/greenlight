<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Reporting\Output\Output;

/**
 * Emits GitHub Actions workflow commands for failures and errors and nothing
 * else, so annotations land on the PR diff without drowning the log. Messages
 * and properties are escaped per the workflow-command encoding rules.
 *
 * @internal
 */
final readonly class GithubReporter implements Reporter
{
    public function __construct(
        private Output $output,
    ) {}

    #[\Override]
    public function onEvent(Event $event): void
    {
        if (!$event instanceof TestFinished) {
            return;
        }

        $result = $event->result;

        if ($result->outcome === Outcome::Failed) {
            $this->writeFailures($result);

            return;
        }

        if ($result->outcome === Outcome::Errored) {
            $this->writeError($result);
        }
    }

    #[\Override]
    public function finish(): void {}

    private function writeFailures(TestResult $result): void
    {
        foreach ($result->failures as $failure) {
            $message = $result->id . ': ' . $failure->message;

            if ($failure->expected !== null) {
                $message .= "\nexpected: " . $failure->expected;
            }

            if ($failure->actual !== null) {
                $message .= "\nactual: " . $failure->actual;
            }

            $location = $failure->location;

            $this->write(
                $location?->file,
                $location?->line,
                $message,
            );
        }

        if ($result->failures === []) {
            $this->write(null, null, $result->id . ': failed.');
        }
    }

    private function writeError(TestResult $result): void
    {
        $error = $result->error;

        if (!$error instanceof ThrowableDetail) {
            $this->write(null, null, $result->id . ': errored.');

            return;
        }

        $this->write($error->file, $error->line, $result->id . ': ' . $error->class . ': ' . $error->message);
    }

    private function write(?string $file, ?int $line, string $message): void
    {
        $properties = '';

        if ($file !== null) {
            $properties = ' file=' . $this->escapeProperty($file);

            if ($line !== null) {
                $properties .= ',line=' . $line;
            }
        }

        $this->output->write('::error' . $properties . '::' . $this->escapeData($message) . "\n");
    }

    private function escapeData(string $value): string
    {
        return \str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], $value);
    }

    private function escapeProperty(string $value): string
    {
        return \str_replace(['%', "\r", "\n", ':', ','], ['%25', '%0D', '%0A', '%3A', '%2C'], $value);
    }
}

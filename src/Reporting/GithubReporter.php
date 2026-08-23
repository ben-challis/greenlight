<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Event\Event;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Internal\Text\Utf8;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;

/**
 * Writes GitHub Actions workflow commands for test failures and errors.
 *
 * Only failures and errors produce output. Thus, annotations occur on the
 * pull request diff, and passed tests do not add log output.
 *
 * The reporter escapes messages and properties with the workflow-command rules.
 *
 * @internal
 */
final class GithubReporter implements Reporter
{
    private ?string $artifactsDirectory = null;
    private bool $hasAttachments = false;

    public function __construct(private readonly Output $output) {}

    #[\Override]
    public function onEvent(Event $event): void
    {
        if ($event instanceof RunStarted) {
            $this->artifactsDirectory = $event->artifactsDirectory;

            return;
        }

        if (!$event instanceof TestFinished) {
            return;
        }

        $result = $event->result;
        $this->hasAttachments = $this->hasAttachments || $result->attachments !== [];

        if ($result->outcome === Outcome::Passed && $result->attempts > 1) {
            $this->writeWarning($result);

            return;
        }

        if ($result->outcome === Outcome::Failed) {
            $this->writeFailures($result);

            return;
        }

        if ($result->outcome === Outcome::Errored) {
            $this->writeError($result);
        }
    }

    #[\Override]
    public function finish(): void
    {
        if ($this->hasAttachments && $this->artifactsDirectory !== null) {
            $this->output->write('::notice::' . $this->escapeData('Greenlight attachments: ' . $this->artifactsDirectory) . "\n");
        }
    }

    /**
     * @throws ReportGenerationFailed
     */
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

            if ($result->attachments !== []) {
                $message .= "\nattachments:\n" . AttachmentFormat::paths($result->attachments);
            }

            $location = $failure->location;

            $this->write(
                $location?->file,
                $location?->line,
                $message,
            );
        }

        if ($result->failures === []) {
            $message = $result->id . ': failed.';

            if ($result->attachments !== []) {
                $message .= "\nattachments:\n" . AttachmentFormat::paths($result->attachments);
            }

            $this->write(null, null, $message);
        }
    }

    /**
     * @throws ReportGenerationFailed
     */
    private function writeError(TestResult $result): void
    {
        $error = $result->error;

        if (!$error instanceof ThrowableDetail) {
            $message = $result->id . ': errored.';

            if ($result->attachments !== []) {
                $message .= "\nattachments:\n" . AttachmentFormat::paths($result->attachments);
            }

            $this->write(null, null, $message);

            return;
        }

        $message = $result->id . ': ' . $error->class . ': ' . $error->message;

        if ($result->attachments !== []) {
            $message .= "\nattachments:\n" . AttachmentFormat::paths($result->attachments);
        }

        $this->write($error->file, $error->line, $message);
    }

    /**
     * @throws ReportGenerationFailed
     */
    private function writeWarning(TestResult $result): void
    {
        $this->output->write(
            '::warning title=Passed after retry::'
            . $this->escapeData(\sprintf(
                '%s passed after %d attempts. This result is evidence of instability.',
                $result->id,
                $result->attempts,
            ))
            . "\n",
        );
    }

    /**
     * @throws ReportGenerationFailed
     */
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
        return \str_replace(['%', "\r", "\n"], ['%25', '%0D', '%0A'], Utf8::scrub($value));
    }

    private function escapeProperty(string $value): string
    {
        return \str_replace(
            ['%', "\r", "\n", ':', ','],
            ['%25', '%0D', '%0A', '%3A', '%2C'],
            Utf8::scrub($value),
        );
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\TestStarted;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Reporting\Output\Output;

/**
 * Emits TeamCity service messages for the run.
 *
 * The stream carries a test suite per class, testStarted and testFinished per
 * test with the duration in milliseconds, testFailed with comparison details,
 * and testIgnored for skips.
 *
 * Values are escaped per the TeamCity service message rules.
 *
 * @internal
 */
final readonly class TeamCityReporter implements Reporter
{
    public function __construct(
        private Output $output,
    ) {}

    #[\Override]
    public function onEvent(Event $event): void
    {
        if ($event instanceof TestClassStarted) {
            $this->message('testSuiteStarted', ['name' => $event->class]);

            return;
        }

        if ($event instanceof TestClassFinished) {
            $this->message('testSuiteFinished', ['name' => $event->class]);

            return;
        }

        if ($event instanceof TestStarted) {
            $this->message('testStarted', ['name' => (string) $event->id]);

            return;
        }

        if ($event instanceof TestFinished) {
            $this->onTestFinished($event->result);
        }
    }

    #[\Override]
    public function finish(): void {}

    private function onTestFinished(TestResult $result): void
    {
        $name = (string) $result->id;

        if ($result->outcome === Outcome::Failed) {
            $this->writeFailed($result, $name);
        }

        if ($result->outcome === Outcome::Errored) {
            $this->writeErrored($result, $name);
        }

        if ($result->outcome === Outcome::Skipped) {
            $this->message('testIgnored', [
                'name' => $name,
                'message' => $result->skipReason ?? 'skipped',
            ]);
        }

        $this->message('testFinished', [
            'name' => $name,
            'duration' => (string) (int) \round($result->durationSeconds * 1000),
        ]);
    }

    private function writeFailed(TestResult $result, string $name): void
    {
        $failure = $result->failures[0] ?? null;

        $attributes = [
            'name' => $name,
            'message' => $failure->message ?? 'failed',
        ];

        $details = [];

        foreach ($result->failures as $index => $detail) {
            if ($index > 0) {
                $details[] = $detail->message;
            }

            if ($detail->location !== null) {
                $details[] = 'at ' . $detail->location;
            }
        }

        if ($details !== []) {
            $attributes['details'] = \implode("\n", $details);
        }

        if ($failure !== null && $failure->expected !== null && $failure->actual !== null) {
            $attributes['type'] = 'comparisonFailure';
            $attributes['expected'] = $failure->expected;
            $attributes['actual'] = $failure->actual;
        }

        $this->message('testFailed', $attributes);
    }

    private function writeErrored(TestResult $result, string $name): void
    {
        $error = $result->error;

        if (!$error instanceof ThrowableDetail) {
            $this->message('testFailed', ['name' => $name, 'message' => 'errored']);

            return;
        }

        $details = $error->stackFrames;
        $details[] = 'at ' . $error->file . ':' . $error->line;

        $this->message('testFailed', [
            'name' => $name,
            'message' => $error->class . ': ' . $error->message,
            'details' => \implode("\n", $details),
        ]);
    }

    /**
     * @param array<non-empty-string, string> $attributes
     */
    private function message(string $name, array $attributes): void
    {
        $rendered = '';

        foreach ($attributes as $key => $value) {
            $rendered .= ' ' . $key . "='" . $this->escape($value) . "'";
        }

        $this->output->write('##teamcity[' . $name . $rendered . "]\n");
    }

    private function escape(string $value): string
    {
        return \str_replace(
            ['|', "'", "\n", "\r", '[', ']'],
            ['||', "|'", '|n', '|r', '|[', '|]'],
            $value,
        );
    }
}

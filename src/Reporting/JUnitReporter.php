<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Reporting\Output\Output;

/**
 * Buffers finished tests and writes JUnit XML at finish(): one testsuite per
 * test class in order of first appearance, one testcase per test, with
 * failure, error, and skipped elements carrying messages and details.
 *
 * @internal
 */
final class JUnitReporter implements Reporter
{
    /**
     * @var array<string, list<TestResult>>
     */
    private array $resultsByClass = [];

    private ?RunFinished $runFinished = null;

    public function __construct(
        private readonly Output $output,
    ) {}

    #[\Override]
    public function onEvent(Event $event): void
    {
        if ($event instanceof TestFinished) {
            $this->resultsByClass[$event->result->id->class][] = $event->result;

            return;
        }

        if ($event instanceof RunFinished) {
            $this->runFinished = $event;
        }
    }

    #[\Override]
    public function finish(): void
    {
        if (!\class_exists(\XMLWriter::class)) {
            throw ReportingError::xmlUnavailable();
        }

        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');
        $writer->startDocument('1.0', 'UTF-8');

        $totals = $this->count(\array_merge([], ...\array_values($this->resultsByClass)));

        $writer->startElement('testsuites');
        $writer->writeAttribute('name', 'greenlight');
        $writer->writeAttribute('tests', (string) $totals['tests']);
        $writer->writeAttribute('failures', (string) $totals['failures']);
        $writer->writeAttribute('errors', (string) $totals['errors']);
        $writer->writeAttribute('skipped', (string) $totals['skipped']);
        $writer->writeAttribute('time', $this->time($this->runFinished->durationSeconds ?? $totals['time']));

        foreach ($this->resultsByClass as $class => $results) {
            $this->writeSuite($writer, $class, $results);
        }

        $writer->endElement();
        $writer->endDocument();

        $this->output->write($writer->outputMemory());
    }

    /**
     * @param list<TestResult> $results
     */
    private function writeSuite(\XMLWriter $writer, string $class, array $results): void
    {
        $counts = $this->count($results);

        $writer->startElement('testsuite');
        $writer->writeAttribute('name', $class);
        $writer->writeAttribute('tests', (string) $counts['tests']);
        $writer->writeAttribute('failures', (string) $counts['failures']);
        $writer->writeAttribute('errors', (string) $counts['errors']);
        $writer->writeAttribute('skipped', (string) $counts['skipped']);
        $writer->writeAttribute('time', $this->time($counts['time']));

        foreach ($results as $result) {
            $this->writeCase($writer, $result);
        }

        $writer->endElement();
    }

    private function writeCase(\XMLWriter $writer, TestResult $result): void
    {
        $name = $result->id->method;

        if ($result->id->dataSetKey !== null) {
            $name .= '[' . $result->id->dataSetKey . ']';
        }

        $writer->startElement('testcase');
        $writer->writeAttribute('name', $name);
        $writer->writeAttribute('classname', $result->id->class);
        $writer->writeAttribute('time', $this->time($result->durationSeconds));

        if ($result->outcome === Outcome::Failed) {
            foreach ($result->failures as $failure) {
                $writer->startElement('failure');
                $writer->writeAttribute('type', 'failure');
                $writer->writeAttribute('message', $failure->message);

                $body = [];

                if ($failure->expected !== null) {
                    $body[] = 'expected: ' . $failure->expected;
                }

                if ($failure->actual !== null) {
                    $body[] = 'actual: ' . $failure->actual;
                }

                if ($failure->location !== null) {
                    $body[] = 'at ' . $failure->location;
                }

                if ($body !== []) {
                    $writer->text(\implode("\n", $body));
                }

                $writer->endElement();
            }
        }

        $error = $result->error;

        if ($result->outcome === Outcome::Errored && $error instanceof ThrowableDetail) {
            $writer->startElement('error');
            $writer->writeAttribute('type', $error->class);
            $writer->writeAttribute('message', $error->message);

            $body = $error->stackFrames;
            $body[] = 'at ' . $error->file . ':' . $error->line;
            $writer->text(\implode("\n", $body));

            $writer->endElement();
        }

        if ($result->outcome === Outcome::Skipped) {
            $writer->startElement('skipped');

            if ($result->skipReason !== null) {
                $writer->writeAttribute('message', $result->skipReason);
            }

            $writer->endElement();
        }

        $writer->endElement();
    }

    /**
     * @param list<TestResult> $results
     *
     * @return array{tests: int, failures: int, errors: int, skipped: int, time: float}
     */
    private function count(array $results): array
    {
        $counts = ['tests' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'time' => 0.0];

        foreach ($results as $result) {
            ++$counts['tests'];
            $counts['time'] += $result->durationSeconds;

            match ($result->outcome) {
                Outcome::Failed => ++$counts['failures'],
                Outcome::Errored => ++$counts['errors'],
                Outcome::Skipped => ++$counts['skipped'],
                Outcome::Passed => null,
            };
        }

        return $counts;
    }

    private function time(float $seconds): string
    {
        return \sprintf('%.6f', $seconds);
    }
}

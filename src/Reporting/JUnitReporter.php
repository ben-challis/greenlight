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
 * Writes JUnit XML for the whole run at finish().
 *
 * The document has one testsuite per test class in order of first appearance
 * and one testcase per test, with failure, error, and skipped elements
 * carrying messages and details.
 *
 * Each testcase is rendered to its XML fragment as its event arrives; live
 * TestResult objects (which may carry large captured-output payloads) are
 * never retained, only the rendered strings and bounded per-class counters.
 *
 * @internal
 */
final class JUnitReporter implements Reporter
{
    /**
     * @var array<string, list<string>> rendered testcase fragments per class
     */
    private array $casesByClass = [];

    /**
     * @var array<string, array{tests: int, failures: int, errors: int, skipped: int, time: float}>
     */
    private array $countsByClass = [];

    private ?float $runDurationSeconds = null;

    public function __construct(
        private readonly Output $output,
    ) {}

    #[\Override]
    public function onEvent(Event $event): void
    {
        if ($event instanceof TestFinished) {
            $result = $event->result;
            $class = $result->id->class;

            $this->casesByClass[$class][] = $this->renderCase($result);
            $counts = $this->countsByClass[$class] ?? ['tests' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'time' => 0.0];
            ++$counts['tests'];
            $counts['time'] += $result->durationSeconds;

            match ($result->outcome) {
                Outcome::Failed => ++$counts['failures'],
                Outcome::Errored => ++$counts['errors'],
                Outcome::Skipped => ++$counts['skipped'],
                Outcome::Passed => null,
            };

            $this->countsByClass[$class] = $counts;

            return;
        }

        if ($event instanceof RunFinished) {
            $this->runDurationSeconds = $event->durationSeconds;
        }
    }

    #[\Override]
    public function finish(): void
    {
        if (!\class_exists(\XMLWriter::class)) {
            throw ReportingError::xmlUnavailable();
        }

        $totals = ['tests' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'time' => 0.0];

        foreach ($this->countsByClass as $counts) {
            $totals['tests'] += $counts['tests'];
            $totals['failures'] += $counts['failures'];
            $totals['errors'] += $counts['errors'];
            $totals['skipped'] += $counts['skipped'];
            $totals['time'] += $counts['time'];
        }

        $this->output->write("<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n");
        $this->output->write(\sprintf(
            "<testsuites name=\"greenlight\" tests=\"%d\" failures=\"%d\" errors=\"%d\" skipped=\"%d\" time=\"%s\">\n",
            $totals['tests'],
            $totals['failures'],
            $totals['errors'],
            $totals['skipped'],
            $this->time($this->runDurationSeconds ?? $totals['time']),
        ));

        foreach ($this->casesByClass as $class => $cases) {
            $counts = $this->countsByClass[$class];

            $this->output->write(\sprintf(
                "  <testsuite name=\"%s\" tests=\"%d\" failures=\"%d\" errors=\"%d\" skipped=\"%d\" time=\"%s\">\n",
                $this->attribute($class),
                $counts['tests'],
                $counts['failures'],
                $counts['errors'],
                $counts['skipped'],
                $this->time($counts['time']),
            ));

            foreach ($cases as $fragment) {
                $this->output->write($fragment);
            }

            $this->output->write("  </testsuite>\n");
        }

        $this->output->write("</testsuites>\n");
    }

    private function attribute(string $value): string
    {
        return \htmlspecialchars($value, \ENT_XML1 | \ENT_COMPAT, 'UTF-8');
    }

    private function renderCase(TestResult $result): string
    {
        if (!\class_exists(\XMLWriter::class)) {
            throw ReportingError::xmlUnavailable();
        }

        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');

        // Dummy ancestors put the fragment at document depth without
        // indenting text nodes; their lines are stripped below.
        $writer->startElement('testsuites');
        $writer->startElement('testsuite');

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
        $writer->endElement();
        $writer->endElement();

        $lines = \explode("\n", \trim($writer->outputMemory(), "\n"));
        $lines = \array_slice($lines, 2, -2);

        return \implode("\n", $lines) . "\n";
    }

    private function time(float $seconds): string
    {
        return \sprintf('%.6f', $seconds);
    }
}

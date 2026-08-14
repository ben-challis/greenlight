<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Result\ThrowableDetail;
use Greenlight\Core\Wire\Utf8;
use Greenlight\Reporting\Output\Output;

/**
 * Writes JUnit XML for the complete run in finish().
 *
 * The document has one testsuite for each test class in order of first
 * occurrence. It has one testcase for each test. Failure, error, and skipped
 * elements contain messages and details.
 *
 * The reporter converts each testcase to XML when its event arrives. It does
 * not retain TestResult objects, which can contain large output capture
 * payloads. It retains only XML text and bounded counters for each class.
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
     * @var array<string, array{tests: int, failures: int, errors: int, skipped: int, assertions: int, time: float}>
     */
    private array $countsByClass = [];

    private ?float $runDurationSeconds = null;

    private readonly XmlWriterRuntime $xmlWriter;

    public function __construct(
        private readonly Output $output,
        ?XmlWriterRuntime $xmlWriter = null,
    ) {
        $this->xmlWriter = $xmlWriter ?? new NativeXmlWriterRuntime();
    }

    #[\Override]
    public function onEvent(Event $event): void
    {
        if ($event instanceof TestFinished) {
            $result = $event->result;
            $class = $result->id->class;

            $this->casesByClass[$class][] = $this->renderCase($result);
            $counts = $this->countsByClass[$class] ?? ['tests' => 0, 'failures' => 0, 'errors' => 0, 'skipped' => 0, 'assertions' => 0, 'time' => 0.0];
            ++$counts['tests'];
            $counts['assertions'] += $result->expectations;
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
        if (!$this->xmlWriter->isAvailable()) {
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
                "  <testsuite name=\"%s\" tests=\"%d\" failures=\"%d\" errors=\"%d\" skipped=\"%d\" assertions=\"%d\" time=\"%s\">\n",
                $this->attribute($class),
                $counts['tests'],
                $counts['failures'],
                $counts['errors'],
                $counts['skipped'],
                $counts['assertions'],
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
        return \htmlspecialchars($this->xml($value), \ENT_XML1 | \ENT_COMPAT, 'UTF-8');
    }

    private function renderCase(TestResult $result): string
    {
        if (!$this->xmlWriter->isAvailable()) {
            throw ReportingError::xmlUnavailable();
        }

        $writer = $this->xmlWriter->create();
        $writer->openMemory();
        $writer->setIndent(true);
        $writer->setIndentString('  ');

        // Temporary ancestors put the fragment at the document depth without
        // text-node indentation. The code below removes their lines.
        $writer->startElement('testsuites');
        $writer->startElement('testsuite');

        $name = $result->id->method;

        if ($result->id->dataSetKey !== null) {
            $name .= '[' . $result->id->dataSetKey . ']';
        }

        $writer->startElement('testcase');
        $writer->writeAttribute('name', $this->xml($name));
        $writer->writeAttribute('classname', $this->xml($result->id->class));
        $writer->writeAttribute('assertions', (string) $result->expectations);
        $writer->writeAttribute('time', $this->time($result->durationSeconds));

        if ($result->outcome === Outcome::Failed) {
            foreach ($result->failures as $failure) {
                $writer->startElement('failure');
                $writer->writeAttribute('type', 'failure');
                $writer->writeAttribute('message', $this->xml($failure->message));

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
                    $writer->text($this->xml(\implode("\n", $body)));
                }

                $writer->endElement();
            }
        }

        $error = $result->error;

        if ($result->outcome === Outcome::Errored) {
            $writer->startElement('error');

            if ($error instanceof ThrowableDetail) {
                $writer->writeAttribute('type', $this->xml($error->class));
                $writer->writeAttribute('message', $this->xml($error->message));

                $body = $error->stackFrames;
                $body[] = 'at ' . $error->file . ':' . $error->line;
                $writer->text($this->xml(\implode("\n", $body)));
            } else {
                $writer->writeAttribute('type', 'error');
                $writer->writeAttribute('message', 'errored');
            }

            $writer->endElement();
        }

        if ($result->outcome === Outcome::Skipped) {
            $writer->startElement('skipped');

            if ($result->skipReason !== null) {
                $writer->writeAttribute('message', $this->xml($result->skipReason));
            }

            $writer->endElement();
        }

        if ($result->attachments !== []) {
            $writer->startElement('system-out');
            $writer->text($this->xml(\implode("\n", \array_map(
                static fn($attachment): string => '[[ATTACHMENT|' . $attachment->path . ']]',
                $result->attachments,
            ))));
            $writer->endElement();
        }

        $writer->endElement();
        $writer->endElement();
        $writer->endElement();

        $lines = \explode("\n", \trim($writer->outputMemory(), "\n"));
        $lines = \array_slice($lines, 2, -2);

        return \implode("\n", $lines) . "\n";
    }

    private function xml(string $value): string
    {
        return (string) \preg_replace(
            '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u',
            "\u{FFFD}",
            Utf8::scrub($value),
        );
    }

    private function time(float $seconds): string
    {
        return \sprintf('%.6f', $seconds);
    }
}

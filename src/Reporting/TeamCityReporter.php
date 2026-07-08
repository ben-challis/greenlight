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
 * onEvent() maps the event stream onto the protocol: a test suite per class,
 * testStarted and testFinished per test with the duration in milliseconds,
 * testFailed with comparison details, and testIgnored for skips.
 *
 * Every message carries a flowId set to the class name, keeping interleaved
 * parallel output untangled for consumers. The class name identifies the
 * flow because a class never spans workers and, unlike a worker id, it is
 * present on test events as well as class events.
 *
 * locationHint() adds php_qn:// navigation hints to testSuiteStarted and
 * testStarted for JetBrains IDEs, resolving the class file by reflection
 * and caching it per class in classFile(). A class that is not loadable in
 * the orchestrator simply loses its hint; the message stays valid.
 *
 * Values are escaped per the TeamCity service message rules.
 *
 * @internal
 */
final class TeamCityReporter implements Reporter
{
    /**
     * @var array<string, ?string>
     */
    private array $classFiles = [];

    public function __construct(
        private readonly Output $output,
    ) {}

    #[\Override]
    public function onEvent(Event $event): void
    {
        if ($event instanceof TestClassStarted) {
            $attributes = ['name' => $event->class];

            $hint = $this->locationHint($event->class);

            if ($hint !== null) {
                $attributes['locationHint'] = $hint;
            }

            $attributes['flowId'] = $event->class;

            $this->message('testSuiteStarted', $attributes);

            return;
        }

        if ($event instanceof TestClassFinished) {
            $this->message('testSuiteFinished', [
                'name' => $event->class,
                'flowId' => $event->class,
            ]);

            return;
        }

        if ($event instanceof TestStarted) {
            $attributes = ['name' => (string) $event->id];

            $hint = $this->locationHint($event->id->class, $event->id->method);

            if ($hint !== null) {
                $attributes['locationHint'] = $hint;
            }

            $attributes['flowId'] = $event->id->class;

            $this->message('testStarted', $attributes);

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
        $flowId = $result->id->class;

        if ($result->outcome === Outcome::Failed) {
            $this->writeFailed($result, $name, $flowId);
        }

        if ($result->outcome === Outcome::Errored) {
            $this->writeErrored($result, $name, $flowId);
        }

        if ($result->outcome === Outcome::Skipped) {
            $this->message('testIgnored', [
                'name' => $name,
                'message' => $result->skipReason ?? 'skipped',
                'flowId' => $flowId,
            ]);
        }

        $this->message('testFinished', [
            'name' => $name,
            'duration' => (string) (int) \round($result->durationSeconds * 1000),
            'flowId' => $flowId,
        ]);
    }

    private function writeFailed(TestResult $result, string $name, string $flowId): void
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

        $attributes['flowId'] = $flowId;

        $this->message('testFailed', $attributes);
    }

    private function writeErrored(TestResult $result, string $name, string $flowId): void
    {
        $error = $result->error;

        if (!$error instanceof ThrowableDetail) {
            $this->message('testFailed', [
                'name' => $name,
                'message' => 'errored',
                'flowId' => $flowId,
            ]);

            return;
        }

        $details = $error->stackFrames;
        $details[] = 'at ' . $error->file . ':' . $error->line;

        $this->message('testFailed', [
            'name' => $name,
            'message' => $error->class . ': ' . $error->message,
            'details' => \implode("\n", $details),
            'flowId' => $flowId,
        ]);
    }

    /**
     * @param non-empty-string $class
     * @param non-empty-string|null $method
     */
    private function locationHint(string $class, ?string $method = null): ?string
    {
        $file = $this->classFile($class);

        if ($file === null) {
            return null;
        }

        $hint = 'php_qn://' . $file . '::\\' . $class;

        return $method === null ? $hint : $hint . '::' . $method;
    }

    /**
     * @param non-empty-string $class
     */
    private function classFile(string $class): ?string
    {
        if (\array_key_exists($class, $this->classFiles)) {
            return $this->classFiles[$class];
        }

        $file = null;

        try {
            if (\class_exists($class)) {
                $reflected = new \ReflectionClass($class)->getFileName();

                if ($reflected !== false) {
                    $file = $reflected;
                }
            }
        } catch (\Throwable) {
            // A throwing autoloader must not take down the report stream.
        }

        return $this->classFiles[$class] = $file;
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

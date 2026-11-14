<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\JsonFrameCodec;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Diagnostic;
use Greenlight\Result\DiagnosticSeverity;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Test\TestId;

use function Greenlight\expect;

final class TestResultWireSizeTest
{
    private const int NINE_MIB = 9 * 1024 * 1024;

    #[Test]
    public function aHugeExceptionMessageIsBoundedWhenTheDetailIsCreated(): void
    {
        $detail = ThrowableDetail::fromThrowable(new \RuntimeException(\str_repeat('x', self::NINE_MIB)));

        expect(\strlen($detail->message))
            ->because('a throwable detail MUST bound its message at creation')
            ->toBeLessThanOrEqual(65_536);
        expect($detail->message)->toEndWith('... (truncated)');
        expect($this->encodedBytes($this->errored($detail)))
            ->because('the result frame MUST fit in the protocol frame limit')
            ->toBeLessThan(JsonFrameCodec::DEFAULT_MAX_FRAME_BYTES);
    }

    #[Test]
    public function manyLargeWarningsFitInOneResultFrame(): void
    {
        $diagnostics = [];

        for ($index = 0; $index < 1_000; ++$index) {
            $diagnostics[] = new Diagnostic(
                DiagnosticSeverity::Warning,
                \str_repeat('w', 9 * 1024),
                '/tests/ExampleTest.php',
                $index + 1,
            );
        }

        $result = new TestResult(
            new TestId('ExampleTest', 'warns'),
            Outcome::Passed,
            0.0,
            0,
            output: new CapturedOutput('', $diagnostics),
        );

        expect(\strlen($diagnostics[0]->message))
            ->because('a diagnostic MUST bound its message at creation')
            ->toBeLessThanOrEqual(2_048);
        expect($this->encodedBytes($result))
            ->because('1,000 large warnings MUST fit in the protocol frame limit')
            ->toBeLessThan(JsonFrameCodec::DEFAULT_MAX_FRAME_BYTES);
    }

    #[Test]
    public function eachWireStringHasOneByteLimit(): void
    {
        $result = new TestResult(
            new TestId('ExampleTest', 'fails'),
            Outcome::Failed,
            0.0,
            0,
            failures: [new FailureDetail('Values differ.', 'expected', \str_repeat('a', self::NINE_MIB))],
            skipReason: \str_repeat('s', self::NINE_MIB),
        );

        $restored = TestResult::fromWire($result->toWire());

        expect(\strlen($restored->failures[0]->actual ?? ''))
            ->because('the wire payload MUST bound each failure string')
            ->toBeLessThanOrEqual(1_048_576);
        expect(\strlen($restored->skipReason ?? ''))->toBeLessThanOrEqual(1_048_576);
        expect($this->encodedBytes($result))->toBeLessThan(JsonFrameCodec::DEFAULT_MAX_FRAME_BYTES);
        expect($restored->failures[0]->expected)
            ->because('short strings MUST cross the wire unchanged')
            ->toBe('expected');
    }

    #[Test]
    public function controlByteWarningsFitInOneResultFrameAfterEscaping(): void
    {
        $diagnostics = [];

        for ($index = 0; $index < 1_000; ++$index) {
            $diagnostics[] = new Diagnostic(
                DiagnosticSeverity::Warning,
                \str_repeat("\x01", 9 * 1024),
                \str_repeat("\x02", 9 * 1024),
                $index + 1,
            );
        }

        $result = new TestResult(
            new TestId('ExampleTest', 'warns'),
            Outcome::Passed,
            0.0,
            0,
            output: new CapturedOutput('', $diagnostics),
        );

        expect($this->encodedBytes($result))
            ->because('JSON escapes each control byte to six bytes, and the limits MUST include that')
            ->toBeLessThan(JsonFrameCodec::DEFAULT_MAX_FRAME_BYTES);
    }

    #[Test]
    public function nonAsciiWireStringsFitInOneResultFrameAfterEscaping(): void
    {
        $text = \str_repeat('é', 2 * 1024 * 1024);
        $result = new TestResult(
            new TestId('ExampleTest', 'fails'),
            Outcome::Failed,
            0.0,
            0,
            failures: [new FailureDetail($text, $text, $text)],
            skipReason: $text,
            output: new CapturedOutput($text),
        );
        $restored = TestResult::fromWire($result->toWire());

        expect($this->encodedBytes($result))
            ->because('JSON escapes each non-ASCII character, and the wire limit MUST include that')
            ->toBeLessThan(JsonFrameCodec::DEFAULT_MAX_FRAME_BYTES);
        expect($restored->failures[0]->actual ?? '')
            ->because('a cut MUST keep whole characters and add the marker')
            ->toEndWith('é... (truncated)');
    }

    private function errored(ThrowableDetail $detail): TestResult
    {
        return new TestResult(new TestId('ExampleTest', 'throws'), Outcome::Errored, 0.0, 0, error: $detail);
    }

    /**
     * Measures the frame that the worker protocol codec sends. A codec without
     * a practical size limit reports the size instead of a frame error.
     */
    private function encodedBytes(TestResult $result): int
    {
        return \strlen(new JsonFrameCodec(\PHP_INT_MAX)->encode(['result' => $result->toWire()]));
    }
}

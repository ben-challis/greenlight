<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Diagnostic;
use Greenlight\Result\DiagnosticSeverity;
use Greenlight\Result\OutputCaptureCapability;
use Greenlight\Tests\Support\JsonWire;

final class CapturedOutputTest
{
    #[Test]
    public function survivesAJsonRoundTrip(): void
    {
        $original = new CapturedOutput(
            'some output',
            [new Diagnostic(DiagnosticSeverity::Warning, 'careful', '/tmp/UserTest.php', 42)],
            true,
            false,
            'standard error',
            true,
            OutputCaptureCapability::ProcessDescriptors,
        );

        $restored = CapturedOutput::fromWire(JsonWire::roundTrip($original->toWire()));

        Expect::that($restored->stdout)->because('survives a JSON round trip')->toBe('some output');
        Expect::that($restored->stdoutTruncated)->toBeTrue();
        Expect::that($restored->diagnosticsTruncated)->toBeFalse();
        Expect::that($restored->stderr)->toBe('standard error');
        Expect::that($restored->stderrTruncated)->toBeTrue();
        Expect::that($restored->capability)->toBe(OutputCaptureCapability::ProcessDescriptors);
        Expect::that($restored->diagnostics)->toHaveCount(1);
        Expect::that($restored->diagnostics[0]->severity)->toBe(DiagnosticSeverity::Warning);
        Expect::that($restored->diagnostics[0]->message)->toBe('careful');
        Expect::that($restored->diagnostics[0]->file)->toBe('/tmp/UserTest.php');
        Expect::that($restored->diagnostics[0]->line)->toBe(42);
    }

    #[Test]
    public function binaryBytesAreScrubbedOnTheWayToTheWire(): void
    {
        $original = new CapturedOutput(
            "stdout with \xB1\x31\xFF bytes",
            [new Diagnostic(DiagnosticSeverity::Notice, "message with \xFE bytes", "/tmp/bad\xFFpath.php", 7)],
        );

        $restored = CapturedOutput::fromWire(JsonWire::roundTrip($original->toWire()));

        Expect::that($restored->stdout)->because('wire serialization replaces invalid bytes')->toMatch('//u')
            ->toContain('stdout with')
            ->toContain('1');
        Expect::that(\preg_match('//u', $restored->diagnostics[0]->message))->toBe(1);
        Expect::that($restored->diagnostics[0]->message)->toContain('message with');
        Expect::that(\preg_match('//u', $restored->diagnostics[0]->file))->toBe(1);
    }

    #[Test]
    public function anEmptyCaptureRoundTrips(): void
    {
        $restored = CapturedOutput::fromWire(JsonWire::roundTrip(new CapturedOutput('')->toWire()));

        Expect::that($restored->stdout)->because('wire serialization preserves an empty capture')->toBe('');
        Expect::that($restored->diagnostics)->toBe([]);
        Expect::that($restored->stdoutTruncated)->toBeFalse();
        Expect::that($restored->diagnosticsTruncated)->toBeFalse();
    }

    #[Test]
    public function legacyOutputPayloadsUseAdditiveFieldDefaults(): void
    {
        $restored = CapturedOutput::fromWire([
            'stdout' => 'legacy output',
            'diagnostics' => [],
            'stdoutTruncated' => false,
            'diagnosticsTruncated' => false,
        ]);

        Expect::that($restored->stderr)->toBe('');
        Expect::that($restored->stderrTruncated)->toBeFalse();
        Expect::that($restored->capability)->toBe(OutputCaptureCapability::Buffered);
    }

    #[Test]
    public function mergeBoundsEveryOutputChannelAndKeepsTheStrongestCapability(): void
    {
        $diagnostic = new Diagnostic(DiagnosticSeverity::Notice, 'notice', 'Test.php', 1);
        $full = new CapturedOutput(
            \str_repeat('o', 1_048_576),
            \array_fill(0, 1_000, $diagnostic),
            capability: OutputCaptureCapability::PhpStreams,
        );
        $overflow = new CapturedOutput(
            'later',
            [$diagnostic],
            capability: OutputCaptureCapability::ProcessDescriptors,
        );

        $merged = CapturedOutput::merge($full, $overflow);

        if (!$merged instanceof CapturedOutput) {
            throw new \RuntimeException('The two output captures did not merge.');
        }

        Expect::that($merged->stdout)->toBe(\str_repeat('o', 1_048_576));
        Expect::that($merged->stdoutTruncated)->toBeTrue();
        Expect::that($merged->diagnostics)->toHaveCount(1_000);
        Expect::that($merged->diagnosticsTruncated)->toBeTrue();
        Expect::that($merged->capability)->toBe(OutputCaptureCapability::ProcessDescriptors);
    }

    #[Test]
    public function anUnknownSeverityOnTheWireIsRejected(): void
    {
        $payload = new Diagnostic(DiagnosticSeverity::Notice, 'm', 'f.php', 1)->toWire();
        $payload['severity'] = 'fatal';

        Expect::that(static fn(): Diagnostic => Diagnostic::fromWire($payload))->because('an unknown severity on the wire is rejected')
            ->toThrow(InvalidWirePayload::class, '/severity/');
    }

    #[Test]
    public function aMissingKeyOnTheWireIsRejected(): void
    {
        Expect::that(static fn(): CapturedOutput => CapturedOutput::fromWire(['stdout' => 'x']))->because('a missing key on the wire is rejected')
            ->toThrow(InvalidWirePayload::class, '/diagnostics/');
    }

}

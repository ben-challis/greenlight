<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Diagnostic;
use Greenlight\Result\DiagnosticSeverity;
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
        );

        $restored = CapturedOutput::fromWire(JsonWire::roundTrip($original->toWire()));

        Expect::value($restored->stdout)->because('survives a JSON round trip')->toBe('some output');
        Expect::value($restored->stdoutTruncated)->toBeTrue();
        Expect::value($restored->diagnosticsTruncated)->toBeFalse();
        Expect::value($restored->diagnostics)->toHaveCount(1);
        Expect::value($restored->diagnostics[0]->severity)->toBe(DiagnosticSeverity::Warning);
        Expect::value($restored->diagnostics[0]->message)->toBe('careful');
        Expect::value($restored->diagnostics[0]->file)->toBe('/tmp/UserTest.php');
        Expect::value($restored->diagnostics[0]->line)->toBe(42);
    }

    #[Test]
    public function binaryBytesAreScrubbedOnTheWayToTheWire(): void
    {
        $original = new CapturedOutput(
            "stdout with \xB1\x31\xFF bytes",
            [new Diagnostic(DiagnosticSeverity::Notice, "message with \xFE bytes", "/tmp/bad\xFFpath.php", 7)],
        );

        $restored = CapturedOutput::fromWire(JsonWire::roundTrip($original->toWire()));

        Expect::value($restored->stdout)->because('wire serialization replaces invalid bytes')->toMatch('//u')
            ->toContain('stdout with')
            ->toContain('1');
        Expect::value(\preg_match('//u', $restored->diagnostics[0]->message))->toBe(1);
        Expect::value($restored->diagnostics[0]->message)->toContain('message with');
        Expect::value(\preg_match('//u', $restored->diagnostics[0]->file))->toBe(1);
    }

    #[Test]
    public function anEmptyCaptureRoundTrips(): void
    {
        $restored = CapturedOutput::fromWire(JsonWire::roundTrip(new CapturedOutput('')->toWire()));

        Expect::value($restored->stdout)->because('wire serialization preserves an empty capture')->toBe('');
        Expect::value($restored->diagnostics)->toBe([]);
        Expect::value($restored->stdoutTruncated)->toBeFalse();
        Expect::value($restored->diagnosticsTruncated)->toBeFalse();
    }

    #[Test]
    public function anUnknownSeverityOnTheWireIsRejected(): void
    {
        $payload = new Diagnostic(DiagnosticSeverity::Notice, 'm', 'f.php', 1)->toWire();
        $payload['severity'] = 'fatal';

        Expect::calling(static fn(): Diagnostic => Diagnostic::fromWire($payload))->because('an unknown severity on the wire is rejected')
            ->toThrow(InvalidWirePayload::class, '/severity/');
    }

    #[Test]
    public function aMissingKeyOnTheWireIsRejected(): void
    {
        Expect::calling(static fn(): CapturedOutput => CapturedOutput::fromWire(['stdout' => 'x']))->because('a missing key on the wire is rejected')
            ->toThrow(InvalidWirePayload::class, '/diagnostics/');
    }

}

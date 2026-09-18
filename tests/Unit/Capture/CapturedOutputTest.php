<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Internal\Wire\InvalidWirePayload;
use Greenlight\Result\CapturedOutput;
use Greenlight\Result\Diagnostic;
use Greenlight\Result\DiagnosticSeverity;
use Greenlight\Tests\Support\JsonWire;

use function Greenlight\expect;

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

        expect($restored->stdout)->because('survives a JSON round trip')->toBe('some output');
        expect($restored->stdoutTruncated)->toBeTrue();
        expect($restored->diagnosticsTruncated)->toBeFalse();
        expect($restored->diagnostics)->toHaveCount(1);
        expect($restored->diagnostics[0]->severity)->toBe(DiagnosticSeverity::Warning);
        expect($restored->diagnostics[0]->message)->toBe('careful');
        expect($restored->diagnostics[0]->file)->toBe('/tmp/UserTest.php');
        expect($restored->diagnostics[0]->line)->toBe(42);
    }

    #[Test]
    public function binaryBytesAreScrubbedOnTheWayToTheWire(): void
    {
        $original = new CapturedOutput(
            "stdout with \xB1\x31\xFF bytes",
            [new Diagnostic(DiagnosticSeverity::Notice, "message with \xFE bytes", "/tmp/bad\xFFpath.php", 7)],
        );

        $restored = CapturedOutput::fromWire(JsonWire::roundTrip($original->toWire()));

        expect($restored->stdout)->because('wire serialization replaces invalid bytes')->toMatch('//u')
            ->toContain('stdout with')
            ->toContain('1');
        expect(\preg_match('//u', $restored->diagnostics[0]->message))->toBe(1);
        expect($restored->diagnostics[0]->message)->toContain('message with');
        expect(\preg_match('//u', $restored->diagnostics[0]->file))->toBe(1);
    }

    #[Test]
    public function anEmptyCaptureRoundTrips(): void
    {
        $restored = CapturedOutput::fromWire(JsonWire::roundTrip(new CapturedOutput('')->toWire()));

        expect($restored->stdout)->because('wire serialization preserves an empty capture')->toBe('');
        expect($restored->diagnostics)->toBe([]);
        expect($restored->stdoutTruncated)->toBeFalse();
        expect($restored->diagnosticsTruncated)->toBeFalse();
    }

    #[Test]
    public function anUnknownSeverityOnTheWireIsRejected(): void
    {
        $payload = new Diagnostic(DiagnosticSeverity::Notice, 'm', 'f.php', 1)->toWire();
        $payload['severity'] = 'fatal';

        expect()->calling(static fn(): Diagnostic => Diagnostic::fromWire($payload))->because('an unknown severity on the wire is rejected')
            ->toThrow(InvalidWirePayload::class, '/severity/');
    }

    #[Test]
    public function aMissingKeyOnTheWireIsRejected(): void
    {
        expect()->calling(static fn(): CapturedOutput => CapturedOutput::fromWire(['stdout' => 'x']))->because('a missing key on the wire is rejected')
            ->toThrow(InvalidWirePayload::class, '/diagnostics/');
    }

}

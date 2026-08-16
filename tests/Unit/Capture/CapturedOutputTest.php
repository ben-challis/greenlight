<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\CapturedOutput;
use Greenlight\Core\Result\Diagnostic;
use Greenlight\Core\Result\DiagnosticSeverity;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;
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

        Expect::that($restored->stdout)->because('survives a JSON round trip')->toBe('some output');
        Expect::that($restored->stdoutTruncated)->toBeTrue();
        Expect::that($restored->diagnosticsTruncated)->toBeFalse();
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

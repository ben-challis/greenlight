<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Capture;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\CapturedOutput;
use Greenlight\Core\Result\Diagnostic;
use Greenlight\Core\Result\DiagnosticSeverity;
use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\Check;

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

        $restored = CapturedOutput::fromWire(Check::jsonRoundTrip($original->toWire()));

        Expect::that($restored->stdout)->toBe('some output')
            ->and($restored->stdoutTruncated)->toBeTrue()
            ->and($restored->diagnosticsTruncated)->toBeFalse()
            ->and($restored->diagnostics)->toHaveCount(1)
            ->and($restored->diagnostics[0]->severity)->toBe(DiagnosticSeverity::Warning)
            ->and($restored->diagnostics[0]->message)->toBe('careful')
            ->and($restored->diagnostics[0]->file)->toBe('/tmp/UserTest.php')
            ->and($restored->diagnostics[0]->line)->toBe(42);
    }

    #[Test]
    public function binaryBytesAreScrubbedOnTheWayToTheWire(): void
    {
        $original = new CapturedOutput(
            "stdout with \xB1\x31\xFF bytes",
            [new Diagnostic(DiagnosticSeverity::Notice, "message with \xFE bytes", "/tmp/bad\xFFpath.php", 7)],
        );

        $restored = CapturedOutput::fromWire(Check::jsonRoundTrip($original->toWire()));

        Expect::that(\preg_match('//u', $restored->stdout))->toBe(1)
            ->and($restored->stdout)->toContain('stdout with')
            ->and($restored->stdout)->toContain('1')
            ->and(\preg_match('//u', $restored->diagnostics[0]->message))->toBe(1)
            ->and($restored->diagnostics[0]->message)->toContain('message with')
            ->and(\preg_match('//u', $restored->diagnostics[0]->file))->toBe(1);
    }

    #[Test]
    public function anEmptyCaptureRoundTrips(): void
    {
        $restored = CapturedOutput::fromWire(Check::jsonRoundTrip(new CapturedOutput('')->toWire()));

        Expect::that($restored->stdout)->toBe('')
            ->and($restored->diagnostics)->toBe([])
            ->and($restored->stdoutTruncated)->toBeFalse()
            ->and($restored->diagnosticsTruncated)->toBeFalse();
    }

    #[Test]
    public function anUnknownSeverityOnTheWireIsRejected(): void
    {
        $payload = new Diagnostic(DiagnosticSeverity::Notice, 'm', 'f.php', 1)->toWire();
        $payload['severity'] = 'fatal';

        Expect::that(static fn(): Diagnostic => Diagnostic::fromWire($payload))
            ->toThrow(InvalidWirePayload::class, '/severity/');
    }

    #[Test]
    public function aMissingKeyOnTheWireIsRejected(): void
    {
        Expect::that(static fn(): CapturedOutput => CapturedOutput::fromWire(['stdout' => 'x']))
            ->toThrow(InvalidWirePayload::class, '/diagnostics/');
    }

}

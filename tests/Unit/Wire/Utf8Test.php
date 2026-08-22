<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Wire;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\Diagnostic;
use Greenlight\Result\DiagnosticSeverity;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Tests\Support\JsonWire;
use Greenlight\Wire\Utf8;

final class Utf8Test
{
    #[Test]
    public function validUtf8PassesThroughUntouched(): void
    {
        Expect::that(Utf8::scrub('plain'))->because('valid UTF-8 remains unchanged')->toBe('plain');
        Expect::that(Utf8::scrub('naïve ✓'))->because('valid UTF-8 remains unchanged')->toBe('naïve ✓');
        Expect::that(Utf8::scrub(''))->because('valid UTF-8 remains unchanged')->toBe('');
    }

    #[Test]
    public function invalidBytesAreSubstituted(): void
    {
        $scrubbed = Utf8::scrub("bad \xB1\x31 bytes");

        Expect::that($scrubbed)
            ->because('each invalid UTF-8 sequence MUST become a replacement character')
            ->toBe("bad \u{FFFD}1 bytes");
    }

    #[Test]
    public function byteBoundsKeepCompleteUnicodeCharacters(): void
    {
        $value = 'ab€cd';

        Expect::that(Utf8::headBytes($value, 4))
            ->because('the head bound MUST exclude a partial Unicode character')
            ->toBe('ab');
        Expect::that(Utf8::tailBytes($value, 4))
            ->because('the tail bound MUST exclude a partial Unicode character')
            ->toBe('cd');
        Expect::that(Utf8::headBytes($value, 5))
            ->because('the head bound MUST keep a complete Unicode character')
            ->toBe('ab€');
        Expect::that(Utf8::tailBytes($value, 5))
            ->because('the tail bound MUST keep a complete Unicode character')
            ->toBe('€cd');
    }

    #[Test]
    public function byteBoundsScrubInvalidInputWithoutExceedingTheLimit(): void
    {
        $value = "a\xFFb";

        Expect::that(Utf8::headBytes($value, 4))
            ->because('the head bound MUST scrub invalid input before it applies the byte limit')
            ->toBe("a\u{FFFD}");
        Expect::that(Utf8::tailBytes($value, 4))
            ->because('the tail bound MUST scrub invalid input before it applies the byte limit')
            ->toBe("\u{FFFD}b");
    }

    #[Test]
    public function byteBoundsRejectNegativeLimits(): void
    {
        Expect::that(static fn(): string => Utf8::headBytes('value', -1))
            ->because('the head byte bound MUST reject a negative limit')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Byte bound must be zero or greater, got -1.',
            );
        Expect::that(static fn(): string => Utf8::tailBytes('value', -2))
            ->because('the tail byte bound MUST reject a negative limit')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Byte bound must be zero or greater, got -2.',
            );
    }

    #[Test]
    public function zeroByteBoundsReturnAnEmptyString(): void
    {
        Expect::that(Utf8::headBytes('value', 0))
            ->because('a zero-byte head cannot retain input')
            ->toBe('');
        Expect::that(Utf8::tailBytes('value', 0))
            ->because('a zero-byte tail cannot retain input')
            ->toBe('');
    }

    #[Test]
    public function throwableWithBinaryMessageSurvivesTheWire(): void
    {
        $detail = ThrowableDetail::fromThrowable(new \RuntimeException("query failed: \xB1\x31\xFF"));
        $restored = ThrowableDetail::fromWire(JsonWire::roundTrip($detail->toWire()));

        Expect::that($restored->class)->because('throwable with binary message survives the wire')->toBe(\RuntimeException::class);
        Expect::that($restored->message)->because('throwable with binary message survives the wire')->toContain('query failed');
    }

    #[Test]
    public function diagnosticWithBinaryMessageAndFileSurvivesTheWire(): void
    {
        $diagnostic = new Diagnostic(
            DiagnosticSeverity::Warning,
            "warning: \xB1 details",
            "/src/\xFF.php",
            42,
        );

        $restored = Diagnostic::fromWire(JsonWire::roundTrip($diagnostic->toWire()));

        Expect::that($restored->severity)
            ->because('the diagnostic severity MUST survive the wire')
            ->toBe(DiagnosticSeverity::Warning);
        Expect::that($restored->message)
            ->because('the diagnostic message MUST replace invalid bytes')
            ->toBe("warning: \u{FFFD} details");
        Expect::that($restored->file)
            ->because('the diagnostic file MUST replace invalid bytes')
            ->toBe("/src/\u{FFFD}.php");
        Expect::that($restored->line)
            ->because('the diagnostic line MUST survive the wire')
            ->toBe(42);
    }
}

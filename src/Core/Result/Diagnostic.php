<?php

declare(strict_types=1);

namespace Greenlight\Core\Result;

use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Utf8;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * One PHP notice, warning, or deprecation recorded during a capture window.
 * Message and file are scrubbed to valid UTF-8 when the diagnostic crosses
 * the wire, because they originate in user code.
 *
 * @internal
 */
final readonly class Diagnostic implements WireSerializable
{
    public function __construct(
        public DiagnosticSeverity $severity,
        public string $message,
        public string $file,
        public int $line,
    ) {}

    #[\Override]
    public function toWire(): array
    {
        return [
            'severity' => $this->severity->value,
            'message' => Utf8::scrub($this->message),
            'file' => Utf8::scrub($this->file),
            'line' => $this->line,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $rawSeverity = Wire::nonEmptyString($payload, 'severity');
        $severity = DiagnosticSeverity::tryFrom($rawSeverity);

        if ($severity === null) {
            throw InvalidWirePayload::wrongType('severity', 'a diagnostic severity', $rawSeverity);
        }

        return new self(
            $severity,
            Wire::string($payload, 'message'),
            Wire::string($payload, 'file'),
            Wire::int($payload, 'line'),
        );
    }
}

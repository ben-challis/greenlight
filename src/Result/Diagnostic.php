<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Internal\Text\Utf8;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Greenlight converts the message and file to valid UTF-8 before it adds the
 * diagnostic to a test result. These values originate in user code. The line
 * number is greater than zero.
 */
final readonly class Diagnostic
{
    /**
     * @var positive-int
     */
    public int $line;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public DiagnosticSeverity $severity,
        public string $message,
        public string $file,
        int $line,
    ) {
        if ($line < 1) {
            throw new \InvalidArgumentException('Diagnostic line MUST be greater than zero.');
        }

        $this->line = $line;
    }

    /**
     * @internal
     *
     * The worker protocol accepts only valid UTF-8 text. Scrub values from
     * user code when Greenlight encodes the diagnostic for transport.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'severity' => $this->severity->value,
            'message' => Utf8::scrub($this->message),
            'file' => Utf8::scrub($this->file),
            'line' => $this->line,
        ];
    }

    /**
     * @internal
     *
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
    public static function fromWire(array $payload): static
    {
        return new self(
            Wire::enum($payload, 'severity', DiagnosticSeverity::class),
            Wire::string($payload, 'message'),
            Wire::string($payload, 'file'),
            \max(1, Wire::int($payload, 'line')),
        );
    }
}

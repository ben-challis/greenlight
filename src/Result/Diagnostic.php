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
 *
 * The message has a limit of 2 KiB. The file has a limit of 1 KiB. Each limit
 * applies to the JSON-encoded size. Longer text keeps its first part and a
 * truncation marker. Output capture keeps at most
 * 1,000 diagnostics for one attempt, so these limits also bound the result
 * size.
 */
final readonly class Diagnostic
{
    private const int MAX_MESSAGE_BYTES = 2_048;
    private const int MAX_FILE_BYTES = 1_024;

    public string $message;
    public string $file;

    /**
     * @var positive-int
     */
    public int $line;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public DiagnosticSeverity $severity,
        string $message,
        string $file,
        int $line,
    ) {
        if ($line < 1) {
            throw new \InvalidArgumentException('Use a diagnostic line number greater than zero.');
        }

        $this->message = BoundedText::head($message, self::MAX_MESSAGE_BYTES);
        $this->file = BoundedText::head($file, self::MAX_FILE_BYTES);
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

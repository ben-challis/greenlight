<?php

declare(strict_types=1);

namespace Greenlight\Core\Result;

use Greenlight\Core\Wire\Utf8;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * When output is too long, Greenlight keeps the first part. This part usually
 * identifies the cause. The last part usually contains repeated information.
 *
 * Greenlight converts standard output to valid UTF-8 before it crosses the wire.
 */
final readonly class CapturedOutput implements WireSerializable
{
    /**
     * @param list<Diagnostic> $diagnostics
     */
    public function __construct(
        public string $stdout,
        public array $diagnostics = [],
        public bool $stdoutTruncated = false,
        public bool $diagnosticsTruncated = false,
    ) {}

    #[\Override]
    public function toWire(): array
    {
        return [
            'stdout' => Utf8::scrub($this->stdout),
            'diagnostics' => \array_map(
                static fn(Diagnostic $diagnostic): array => $diagnostic->toWire(),
                $this->diagnostics,
            ),
            'stdoutTruncated' => $this->stdoutTruncated,
            'diagnosticsTruncated' => $this->diagnosticsTruncated,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $diagnostics = [];

        foreach (Wire::listOfMaps($payload, 'diagnostics') as $map) {
            $diagnostics[] = Diagnostic::fromWire($map);
        }

        return new self(
            Wire::string($payload, 'stdout'),
            $diagnostics,
            Wire::bool($payload, 'stdoutTruncated'),
            Wire::bool($payload, 'diagnosticsTruncated'),
        );
    }
}

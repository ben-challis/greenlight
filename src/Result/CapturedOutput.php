<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Internal\Text\Utf8;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * When output is too long, Greenlight keeps the first part. This part usually
 * identifies the cause. The last part usually contains repeated information.
 *
 * Greenlight converts standard output to valid UTF-8 before it crosses the wire.
 */
final readonly class CapturedOutput
{
    /**
     * @var list<Diagnostic>
     */
    public array $diagnostics;

    /**
     * @param array<mixed> $diagnostics
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public string $stdout,
        array $diagnostics = [],
        public bool $stdoutTruncated = false,
        public bool $diagnosticsTruncated = false,
    ) {
        $validatedDiagnostics = [];

        foreach ($diagnostics as $index => $diagnostic) {
            if ($index !== \count($validatedDiagnostics) || !$diagnostic instanceof Diagnostic) {
                throw new \InvalidArgumentException(
                    'Captured output diagnostics MUST be a list of Diagnostic instances.',
                );
            }

            $validatedDiagnostics[] = $diagnostic;
        }

        $this->diagnostics = $validatedDiagnostics;
    }

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
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

    /**
     * @internal
     *
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
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

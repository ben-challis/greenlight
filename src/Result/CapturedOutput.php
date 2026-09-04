<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Internal\Text\Utf8;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Contains standard output, diagnostics, and truncation flags for one attempt.
 * When output exceeds the capture limit, Greenlight keeps the first part.
 *
 * Greenlight converts captured standard output to valid UTF-8 before it adds
 * the output to a test result.
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
                    'Use a list of Diagnostic instances for captured output diagnostics.',
                );
            }

            $validatedDiagnostics[] = $diagnostic;
        }

        $this->diagnostics = $validatedDiagnostics;
    }

    /**
     * @internal
     *
     * The worker protocol accepts only valid UTF-8 text. Scrub standard output
     * when Greenlight encodes it for transport.
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

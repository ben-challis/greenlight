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
 * Greenlight keeps at most 1 MiB from each standard stream and 1,000
 * diagnostics. It converts captured stream data to valid UTF-8 before it adds
 * the data to a test result.
 */
final readonly class CapturedOutput
{
    private const int MAX_STDOUT_BYTES = 1_048_576;
    private const int MAX_STDERR_BYTES = 1_048_576;
    private const int MAX_DIAGNOSTICS = 1_000;

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
        public string $stderr = '',
        public bool $stderrTruncated = false,
        public OutputCaptureCapability $capability = OutputCaptureCapability::Buffered,
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
     * The worker protocol accepts only valid UTF-8 text. Scrub standard output
     * when Greenlight encodes it for transport.
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'stdout' => Utf8::scrub($this->stdout),
            'stderr' => Utf8::scrub($this->stderr),
            'diagnostics' => \array_map(
                static fn(Diagnostic $diagnostic): array => $diagnostic->toWire(),
                $this->diagnostics,
            ),
            'stdoutTruncated' => $this->stdoutTruncated,
            'stderrTruncated' => $this->stderrTruncated,
            'diagnosticsTruncated' => $this->diagnosticsTruncated,
            'capability' => $this->capability->value,
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
            \array_key_exists('stderr', $payload) ? Wire::string($payload, 'stderr') : '',
            \array_key_exists('stderrTruncated', $payload) && Wire::bool($payload, 'stderrTruncated'),
            \array_key_exists('capability', $payload)
                ? Wire::enum($payload, 'capability', OutputCaptureCapability::class)
                : OutputCaptureCapability::Buffered,
        );
    }

    /**
     * Combines attempt and transport output within the per-test limits.
     *
     * @internal
     */
    public static function merge(?self ...$outputs): ?self
    {
        $stdout = '';
        $stderr = '';
        $diagnostics = [];
        $stdoutTruncated = false;
        $stderrTruncated = false;
        $diagnosticsTruncated = false;
        $capability = OutputCaptureCapability::Buffered;
        $present = false;

        foreach ($outputs as $output) {
            if (!$output instanceof self) {
                continue;
            }

            $present = true;
            [$stdout, $stdoutCut] = self::appendBounded($stdout, $output->stdout, self::MAX_STDOUT_BYTES);
            [$stderr, $stderrCut] = self::appendBounded($stderr, $output->stderr, self::MAX_STDERR_BYTES);
            $stdoutTruncated = $stdoutTruncated || $output->stdoutTruncated || $stdoutCut;
            $stderrTruncated = $stderrTruncated || $output->stderrTruncated || $stderrCut;

            foreach ($output->diagnostics as $diagnostic) {
                if (\count($diagnostics) >= self::MAX_DIAGNOSTICS) {
                    $diagnosticsTruncated = true;

                    break;
                }

                $diagnostics[] = $diagnostic;
            }

            $diagnosticsTruncated = $diagnosticsTruncated || $output->diagnosticsTruncated;
            $capability = match (true) {
                $output->capability === OutputCaptureCapability::ProcessDescriptors => OutputCaptureCapability::ProcessDescriptors,
                $output->capability === OutputCaptureCapability::PhpStreams
                    && $capability === OutputCaptureCapability::Buffered => OutputCaptureCapability::PhpStreams,
                default => $capability,
            };
        }

        if (!$present) {
            return null;
        }

        return new self(
            $stdout,
            $diagnostics,
            $stdoutTruncated,
            $diagnosticsTruncated,
            $stderr,
            $stderrTruncated,
            $capability,
        );
    }

    /**
     * Creates bounded text from raw descriptor bytes.
     *
     * @internal
     */
    public static function fromProcessDescriptors(
        string $stdout,
        string $stderr,
        bool $stdoutTruncated = false,
        bool $stderrTruncated = false,
    ): self {
        $scrubbedStdout = Utf8::scrub($stdout);
        $scrubbedStderr = Utf8::scrub($stderr);
        $boundedStdout = Utf8::headBytes($scrubbedStdout, self::MAX_STDOUT_BYTES);
        $boundedStderr = Utf8::headBytes($scrubbedStderr, self::MAX_STDERR_BYTES);

        return new self(
            $boundedStdout,
            stdoutTruncated: $stdoutTruncated || \strlen($boundedStdout) < \strlen($scrubbedStdout),
            stderr: $boundedStderr,
            stderrTruncated: $stderrTruncated || \strlen($boundedStderr) < \strlen($scrubbedStderr),
            capability: OutputCaptureCapability::ProcessDescriptors,
        );
    }

    /** @return array{string, bool} */
    private static function appendBounded(string $current, string $addition, int $maxBytes): array
    {
        if (\strlen($current) >= $maxBytes) {
            return [$current, $addition !== ''];
        }

        $combined = Utf8::scrub($current . $addition);
        $bounded = Utf8::headBytes($combined, $maxBytes);

        return [$bounded, \strlen($bounded) < \strlen($combined)];
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Internal\Text\Wildcard;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * CI rules that can change a passed test to a failed test.
 *
 * Greenlight applies the policy through its terminal-result plugin. This call
 * occurs after retries and afterTest subscribers. Thus, each consumer receives
 * the same result.
 *
 * The applicable flag changes a passed test with a captured deprecation,
 * notice, or warning to a failed test. The diagnostic becomes the failure
 * detail. The transformation log records the change.
 *
 * A pattern without "*" or "?" matches part of a deprecation message without
 * case sensitivity. A pattern with either character matches the complete
 * message.
 *
 * A passed test with no verified expectations becomes risky. failOnRisky
 * changes this result to failed.
 *
 * @internal
 */
final readonly class ResultPolicy
{
    /**
     * @var list<non-empty-string>
     */
    public array $ignoreDeprecations;

    /**
     * @param array<mixed> $ignoreDeprecations
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        public bool $failOnDeprecation = false,
        public bool $failOnNotice = false,
        public bool $failOnWarning = false,
        array $ignoreDeprecations = [],
        public bool $failOnRisky = false,
    ) {
        $validatedPatterns = [];

        foreach ($ignoreDeprecations as $index => $pattern) {
            if ($index !== \count($validatedPatterns) || !\is_string($pattern) || $pattern === '') {
                throw new \InvalidArgumentException(
                    'Use a list of non-empty strings for deprecation ignore patterns.',
                );
            }

            $validatedPatterns[] = $pattern;
        }

        $this->ignoreDeprecations = $validatedPatterns;
    }

    public function isNoOp(): bool
    {
        return !$this->failOnDeprecation && !$this->failOnNotice && !$this->failOnWarning && !$this->failOnRisky;
    }

    public function apply(TestResult $result): TestResult
    {
        if ($result->outcome !== Outcome::Passed) {
            return $result;
        }

        $details = [];

        foreach ($result->output->diagnostics ?? [] as $diagnostic) {
            $offends = match ($diagnostic->severity) {
                DiagnosticSeverity::Deprecation => $this->failOnDeprecation && !$this->ignored($diagnostic->message),
                DiagnosticSeverity::Notice => $this->failOnNotice,
                DiagnosticSeverity::Warning => $this->failOnWarning,
            };

            if ($offends) {
                $details[] = new FailureDetail(\sprintf(
                    'The %s policy changed this test from passed to failed: %s at %s:%d',
                    $diagnostic->severity->value,
                    $diagnostic->message,
                    $diagnostic->file,
                    $diagnostic->line,
                ));
            }
        }

        if ($details !== []) {
            return $result->failedBy('fail-on-diagnostic policy', $details);
        }

        if ($result->risky && $this->failOnRisky) {
            return $result->failedBy('fail-on-risky policy', [new FailureDetail(
                'The fail-on-risky policy changed this test from passed to failed because it verified no expectations.',
            )]);
        }

        return $result;
    }

    private function ignored(string $message): bool
    {
        return \array_any(
            $this->ignoreDeprecations,
            static fn(string $pattern): bool => Wildcard::matches($message, $pattern, caseInsensitive: true),
        );
    }

    /** @return array<string, mixed> */
    public function toWire(): array
    {
        return [
            'failOnDeprecation' => $this->failOnDeprecation,
            'failOnNotice' => $this->failOnNotice,
            'failOnWarning' => $this->failOnWarning,
            'ignoreDeprecations' => $this->ignoreDeprecations,
            'failOnRisky' => $this->failOnRisky,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @throws WireCommunicationFailed
     */
    public static function fromWire(array $payload): static
    {
        $patterns = [];

        foreach (Wire::listOfStrings($payload, 'ignoreDeprecations') as $pattern) {
            if ($pattern !== '') {
                $patterns[] = $pattern;
            }
        }

        return new self(
            Wire::bool($payload, 'failOnDeprecation'),
            Wire::bool($payload, 'failOnNotice'),
            \array_key_exists('failOnWarning', $payload) && Wire::bool($payload, 'failOnWarning'),
            $patterns,
            Wire::bool($payload, 'failOnRisky'),
        );
    }
}

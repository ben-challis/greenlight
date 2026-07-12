<?php

declare(strict_types=1);

namespace Greenlight\Core\Result;

use Greenlight\Core\Wildcard;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Result-level CI gates that can fail a passed test.
 *
 * apply() is called by the worker on each final result, after retries and
 * afterTest subscribers, so every consumer, from exit code to junit to
 * plugins, sees the same truth.
 *
 * A passed test with a captured deprecation or notice fails under the
 * matching flag, with the diagnostic as the failure detail and the flip
 * recorded as a provenance transformation.
 *
 * The ignore list exempts deprecation messages by case-insensitive
 * substring, or whole-message match when the pattern contains "*" or "?".
 *
 * A passed test that verified no expectations is marked risky, and failed
 * outright under failOnRisky.
 *
 * @internal
 */
final readonly class ResultPolicy implements WireSerializable
{
    /**
     * @param list<non-empty-string> $ignoreDeprecations
     */
    public function __construct(
        public bool $failOnDeprecation = false,
        public bool $failOnNotice = false,
        public array $ignoreDeprecations = [],
        public bool $failOnRisky = false,
    ) {}

    public function isNoOp(): bool
    {
        return !$this->failOnDeprecation && !$this->failOnNotice && !$this->failOnRisky;
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
                default => false,
            };

            if ($offends) {
                $details[] = new FailureDetail(\sprintf(
                    'The %s policy failed this passed test: %s at %s:%d',
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
                'The fail-on-risky policy failed this passed test: it verified no expectations.',
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

    #[\Override]
    public function toWire(): array
    {
        return [
            'failOnDeprecation' => $this->failOnDeprecation,
            'failOnNotice' => $this->failOnNotice,
            'ignoreDeprecations' => $this->ignoreDeprecations,
            'failOnRisky' => $this->failOnRisky,
        ];
    }

    #[\Override]
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
            $patterns,
            Wire::bool($payload, 'failOnRisky'),
        );
    }
}

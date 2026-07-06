<?php

declare(strict_types=1);

namespace Greenlight\Core\Result;

use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * Bounded aggregate counts for a run. Reporters that need aggregates keep
 * summaries like this one, never per-test collections.
 *
 * @internal
 */
final readonly class ResultSummary implements WireSerializable
{
    /**
     * @param non-negative-int $passed
     * @param non-negative-int $failed
     * @param non-negative-int $errored
     * @param non-negative-int $skipped
     */
    public function __construct(
        public int $passed = 0,
        public int $failed = 0,
        public int $errored = 0,
        public int $skipped = 0,
    ) {}

    public function add(Outcome $outcome): self
    {
        return new self(
            $this->passed + ($outcome === Outcome::Passed ? 1 : 0),
            $this->failed + ($outcome === Outcome::Failed ? 1 : 0),
            $this->errored + ($outcome === Outcome::Errored ? 1 : 0),
            $this->skipped + ($outcome === Outcome::Skipped ? 1 : 0),
        );
    }

    /**
     * @return non-negative-int
     */
    public function total(): int
    {
        return $this->passed + $this->failed + $this->errored + $this->skipped;
    }

    public function isSuccessful(): bool
    {
        return $this->failed === 0 && $this->errored === 0;
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'passed' => $this->passed,
            'failed' => $this->failed,
            'errored' => $this->errored,
            'skipped' => $this->skipped,
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        return new self(
            \max(0, Wire::int($payload, 'passed')),
            \max(0, Wire::int($payload, 'failed')),
            \max(0, Wire::int($payload, 'errored')),
            \max(0, Wire::int($payload, 'skipped')),
        );
    }
}

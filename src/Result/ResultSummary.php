<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Wire\Wire;
use Greenlight\Wire\WireSerializable;

/** Aggregate outcome counts for a run. */
final readonly class ResultSummary implements WireSerializable
{
    /**
     * @var non-negative-int
     */
    public int $passed;

    /**
     * @var non-negative-int
     */
    public int $failed;

    /**
     * @var non-negative-int
     */
    public int $errored;

    /**
     * @var non-negative-int
     */
    public int $skipped;

    /**
     * @throws \InvalidArgumentException
     */
    public function __construct(
        int $passed = 0,
        int $failed = 0,
        int $errored = 0,
        int $skipped = 0,
    ) {
        if ($passed < 0) {
            throw new \InvalidArgumentException('Result summary passed count MUST NOT be negative.');
        }

        if ($failed < 0) {
            throw new \InvalidArgumentException('Result summary failed count MUST NOT be negative.');
        }

        if ($errored < 0) {
            throw new \InvalidArgumentException('Result summary errored count MUST NOT be negative.');
        }

        if ($skipped < 0) {
            throw new \InvalidArgumentException('Result summary skipped count MUST NOT be negative.');
        }

        $this->passed = $passed;
        $this->failed = $failed;
        $this->errored = $errored;
        $this->skipped = $skipped;
    }

    public function add(Outcome $outcome): self
    {
        return new self(
            $this->saturatedSum($this->passed, $outcome === Outcome::Passed ? 1 : 0),
            $this->saturatedSum($this->failed, $outcome === Outcome::Failed ? 1 : 0),
            $this->saturatedSum($this->errored, $outcome === Outcome::Errored ? 1 : 0),
            $this->saturatedSum($this->skipped, $outcome === Outcome::Skipped ? 1 : 0),
        );
    }

    /**
     * @return non-negative-int
     */
    public function total(): int
    {
        return $this->saturatedSum($this->passed, $this->failed, $this->errored, $this->skipped);
    }

    public function isSuccessful(): bool
    {
        return $this->failed === 0 && $this->errored === 0;
    }

    /**
     * @param non-negative-int ...$counts
     *
     * @return non-negative-int
     */
    private function saturatedSum(int ...$counts): int
    {
        $total = 0;

        foreach ($counts as $count) {
            if ($count > \PHP_INT_MAX - $total) {
                return \PHP_INT_MAX;
            }

            $total += $count;
        }

        return $total;
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

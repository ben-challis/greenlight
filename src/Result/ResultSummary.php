<?php

declare(strict_types=1);

namespace Greenlight\Result;

use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/** Aggregate outcome counts for a run. */
final readonly class ResultSummary
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
            throw new \InvalidArgumentException('Result summary passed count cannot be negative.');
        }

        if ($failed < 0) {
            throw new \InvalidArgumentException('Result summary failed count cannot be negative.');
        }

        if ($errored < 0) {
            throw new \InvalidArgumentException('Result summary errored count cannot be negative.');
        }

        if ($skipped < 0) {
            throw new \InvalidArgumentException('Result summary skipped count cannot be negative.');
        }

        $this->passed = $passed;
        $this->failed = $failed;
        $this->errored = $errored;
        $this->skipped = $skipped;
    }

    public function add(Outcome $outcome): self
    {
        $max = \PHP_INT_MAX;

        return match ($outcome) {
            Outcome::Passed => new self($this->passed === $max ? $max : $this->passed + 1, $this->failed, $this->errored, $this->skipped),
            Outcome::Failed => new self($this->passed, $this->failed === $max ? $max : $this->failed + 1, $this->errored, $this->skipped),
            Outcome::Errored => new self($this->passed, $this->failed, $this->errored === $max ? $max : $this->errored + 1, $this->skipped),
            Outcome::Skipped => new self($this->passed, $this->failed, $this->errored, $this->skipped === $max ? $max : $this->skipped + 1),
        };
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

    /**
     * @internal
     *
     * @return array<string, mixed>
     */
    public function toWire(): array
    {
        return [
            'passed' => $this->passed,
            'failed' => $this->failed,
            'errored' => $this->errored,
            'skipped' => $this->skipped,
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
            \max(0, Wire::int($payload, 'passed')),
            \max(0, Wire::int($payload, 'failed')),
            \max(0, Wire::int($payload, 'errored')),
            \max(0, Wire::int($payload, 'skipped')),
        );
    }
}

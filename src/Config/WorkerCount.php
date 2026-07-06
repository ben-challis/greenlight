<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * The requested worker pool size. 'auto' is kept as an explicit marker; the
 * runner decides what it means (typically the number of available cores).
 * Configuration code never guesses a CPU count.
 *
 * @internal
 */
final readonly class WorkerCount
{
    /**
     * @param positive-int|null $fixed null means resolve automatically at run time
     */
    private function __construct(public ?int $fixed) {}

    public static function auto(): self
    {
        return new self(null);
    }

    /**
     * @throws InvalidConfiguration
     */
    public static function exactly(int $count): self
    {
        if ($count < 1) {
            throw new InvalidConfiguration(\sprintf('Worker count must be at least 1, got %d.', $count));
        }

        return new self($count);
    }

    public function isAuto(): bool
    {
        return $this->fixed === null;
    }

    public function describe(): string
    {
        return $this->fixed === null ? 'auto' : (string) $this->fixed;
    }
}

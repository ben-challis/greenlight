<?php

declare(strict_types=1);

namespace Greenlight\Config;

/**
 * The runner resolves 'auto'. The configuration does not estimate the number
 * of CPU cores.
 *
 * @internal
 */
final readonly class WorkerCount
{
    /**
     * @param positive-int|null $fixed A null value makes the runner select the
     *   worker count at run time.
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

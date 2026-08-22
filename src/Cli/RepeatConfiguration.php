<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * Defines command repetition outside one resolved run.
 *
 * @internal
 */
final readonly class RepeatConfiguration
{
    /** @param positive-int|null $count */
    public function __construct(
        public ?int $count = null,
        public bool $untilFailure = false,
    ) {}

    public function usesRepeatMode(): bool
    {
        return $this->untilFailure || ($this->count !== null && $this->count > 1);
    }
}

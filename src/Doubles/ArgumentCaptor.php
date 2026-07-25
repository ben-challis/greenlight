<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Records the argument in its position each time the surrounding
 * expectation answers a call.
 *
 * matches() always accepts and never records; capturing happens only for
 * the expectation that won the call, so probing candidate expectations
 * cannot pollute a captor.
 */
final class ArgumentCaptor implements ArgumentMatcher
{
    /**
     * @var list<mixed>
     */
    private array $captured = [];

    public function matches(mixed $value): bool
    {
        return true;
    }

    public function describe(): string
    {
        return 'captor()';
    }

    /** @internal */
    public function capture(mixed $value): void
    {
        $this->captured[] = $value;
    }

    /**
     * @return list<mixed>
     */
    public function values(): array
    {
        return $this->captured;
    }

    public function value(): mixed
    {
        if ($this->captured === []) {
            throw DoublesError::nothingCaptured();
        }

        return $this->captured[\count($this->captured) - 1];
    }
}

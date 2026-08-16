<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * A captor records the argument in its position. It records the argument when
 * Greenlight selects the related expectation for the call.
 *
 * `matches()` always accepts the value and does not record it. Only the
 * expectation selected for the call can record an argument. Thus, checks of
 * candidate expectations cannot add values to a captor.
 *
 * @template TValue = mixed
 */
final class ArgumentCaptor implements ArgumentMatcher
{
    /**
     * @var list<TValue>
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

    /**
     * @param TValue $value
     *
     * @internal
     */
    public function capture(mixed $value): void
    {
        $this->captured[] = $value;
    }

    /**
     * @return list<TValue>
     */
    public function values(): array
    {
        return $this->captured;
    }

    /** @return TValue */
    public function value(): mixed
    {
        if ($this->captured === []) {
            throw DoublesError::nothingCaptured();
        }

        return $this->captured[\count($this->captured) - 1];
    }
}

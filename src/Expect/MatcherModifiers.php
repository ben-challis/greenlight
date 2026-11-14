<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Result\FailureDetail;

/**
 * Holds the `not()` and `because()` state of one expectation stage.
 *
 * Negation applies to the next matcher only. `handOff()` gives the pending
 * negation and the reason to the next stage and clears the negation here. The
 * reason stays for later matchers in the chain.
 *
 * @internal
 */
final class MatcherModifiers
{
    private bool $negated = false;

    /** @var non-empty-string|null */
    private ?string $reason = null;

    public function negate(): void
    {
        $this->negated = true;
    }

    /**
     * Sets the reason. An empty reason causes a usage failure.
     *
     * @param (\Closure(): string)|null $actual Renders the actual value for
     *   the usage failure
     *
     * @throws ExpectationFailed
     */
    public function because(string $reason, ?\Closure $actual = null): void
    {
        $reason = \trim($reason);

        if ($reason === '') {
            $this->negated = false;

            throw ExpectationFailed::fromDetail(new FailureDetail(
                'because() requires a non-empty reason.',
                null,
                $actual instanceof \Closure ? $actual() : null,
                CallSite::capture(),
            ));
        }

        $this->reason = $reason;
    }

    public function isNegated(): bool
    {
        return $this->negated;
    }

    /** @return non-empty-string|null */
    public function reason(): ?string
    {
        return $this->reason;
    }

    /** Returns the pending negation and clears it. */
    public function takeNegation(): bool
    {
        $negated = $this->negated;
        $this->negated = false;

        return $negated;
    }

    /**
     * Returns a copy for the next stage and clears the pending negation here.
     */
    public function handOff(): self
    {
        $next = new self();
        $next->negated = $this->takeNegation();
        $next->reason = $this->reason;

        return $next;
    }

    /**
     * Applies this state to a stage through its `not()` and `because()`
     * methods. This object keeps its state.
     *
     * @param \Closure(): mixed $not
     * @param \Closure(non-empty-string): mixed $because
     *
     * @throws ExpectationFailed
     */
    public function applyTo(\Closure $not, \Closure $because): void
    {
        if ($this->negated) {
            $not();
        }

        if ($this->reason !== null) {
            $because($this->reason);
        }
    }

    /**
     * Gives the pending negation and the reason to the next stage. This
     * object loses the pending negation.
     *
     * @param \Closure(): mixed $not
     * @param \Closure(non-empty-string): mixed $because
     *
     * @throws ExpectationFailed
     */
    public function passOn(\Closure $not, \Closure $because): void
    {
        $this->handOff()->applyTo($not, $because);
    }
}

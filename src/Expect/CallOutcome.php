<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Captures one invocation, including a null return or a thrown error.
 *
 * @internal
 * @template T
 */
final readonly class CallOutcome
{
    /** @param \Closure(): T $replay */
    private function __construct(private \Closure $replay) {}

    /**
     * @template TResult
     * @param \Closure(): TResult $call
     * @return self<TResult>
     */
    public static function capture(\Closure $call): self
    {
        try {
            $value = $call();
            return new self(static fn() => $value);
        } catch (\Throwable $throwable) {
            return new self(static fn() => throw $throwable);
        }
    }

    /** @return T */
    public function replay(): mixed
    {
        return ($this->replay)();
    }
}

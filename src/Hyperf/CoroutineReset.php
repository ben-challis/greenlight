<?php

declare(strict_types=1);

namespace Greenlight\Hyperf;

/**
 * Runs the reset of one test coroutine at most once.
 *
 * The test coroutine and the waiting coroutine can both call `run()`. The
 * waiting coroutine calls it when it abandons a blocked test coroutine. A later
 * call from the resumed test coroutine then does nothing, so it cannot reset
 * the state of the next attempt.
 *
 * @internal
 */
final class CoroutineReset
{
    private bool $done = false;

    /** @param \Closure(): void $reset */
    public function __construct(private readonly \Closure $reset) {}

    public function run(): void
    {
        if ($this->done) {
            return;
        }

        $this->done = true;
        ($this->reset)();
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Hyperf;

/**
 * Waits for a test coroutine until the test deadline.
 *
 * `await()` returns `true` when the coroutine reports completion and `false`
 * when the deadline passes first. The test attempt sets its deadline inside the
 * coroutine. Until the attempt starts, the wait polls in short steps, because
 * container setup can take any time. After the attempt starts, the wait reads
 * the deadline again after one short wait. Without a deadline, the wait then
 * has no limit.
 *
 * @internal
 */
final class CoroutineWait
{
    /** The wait, in seconds, before the deadline is known. */
    public const float FIRST_WAIT_SECONDS = 0.1;

    /** The shortest wait, in seconds, for a deadline that has almost passed. */
    public const float MINIMUM_WAIT_SECONDS = 0.001;

    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param \Closure(float): bool $pop
     *   Waits for completion for the given seconds. A negative value waits
     *   without a limit. Returns `true` when the coroutine finished.
     * @param \Closure(): ?float $deadline
     *   Returns the test deadline in monotonic seconds, or `null`.
     * @param \Closure(): float $now
     *   Returns the current time in monotonic seconds.
     * @param \Closure(): bool $attemptStarted
     *   Returns `true` when the coroutine has started the test attempt.
     */
    public static function await(\Closure $pop, \Closure $deadline, \Closure $now, \Closure $attemptStarted): bool
    {
        $limit = null;
        $waitedAfterStart = false;

        while (true) {
            $limit ??= $deadline();

            if ($limit === null) {
                if ($attemptStarted()) {
                    if ($waitedAfterStart) {
                        return $pop(-1.0);
                    }

                    $waitedAfterStart = true;
                }

                if ($pop(self::FIRST_WAIT_SECONDS)) {
                    return true;
                }

                continue;
            }

            if ($pop(\max($limit - $now(), self::MINIMUM_WAIT_SECONDS))) {
                return true;
            }

            if ($now() >= $limit) {
                return false;
            }
        }
    }
}

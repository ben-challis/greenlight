<?php

declare(strict_types=1);

namespace Greenlight\Hyperf;

/**
 * Waits for a test coroutine until the test deadline.
 *
 * `await()` returns `true` when the coroutine reports completion and `false`
 * when the deadline passes first. The test attempt sets its deadline inside the
 * coroutine, so the wait reads the deadline again after a short first wait.
 * Without a deadline, the wait has no limit.
 *
 * @internal
 */
final class CoroutineWait
{
    /** The first wait, in seconds, before the deadline is known. */
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
     */
    public static function await(\Closure $pop, \Closure $deadline, \Closure $now): bool
    {
        $limit = null;
        $firstWaitDone = false;

        while (true) {
            $limit ??= $deadline();

            if ($limit === null) {
                if ($firstWaitDone) {
                    return $pop(-1.0);
                }

                $firstWaitDone = true;

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

<?php

declare(strict_types=1);

namespace Greenlight\Hyperf;

use Greenlight\Harness\ServiceResolutionFailed;

/**
 * Converts the state of a finished Swoole runtime into the attempt result.
 *
 * `value()` separates a coroutine that did not start from a coroutine that
 * started but did not finish. In the second case, all coroutines were blocked
 * and Swoole ended the runtime.
 *
 * @internal
 */
final class CoroutineOutcome
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @template T
     *
     * @param array{value: T}|array{} $result
     *
     * @return T
     * @throws ServiceResolutionFailed
     */
    public static function value(bool $started, array $result, ?\Throwable $failure): mixed
    {
        if (!$started) {
            throw HyperfBridgeError::coroutineDidNotStart();
        }

        if ($failure instanceof \Throwable) {
            throw $failure;
        }

        if (!\array_key_exists('value', $result)) {
            throw HyperfBridgeError::coroutineBlocked();
        }

        return $result['value'];
    }
}

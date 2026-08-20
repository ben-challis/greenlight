<?php

declare(strict_types=1);

namespace Greenlight\Core\Test;

/**
 * Stores cleanup callbacks for one test attempt. Greenlight runs the callbacks
 * in reverse registration order after the `After` hooks. Per-test service
 * disposal starts after the callbacks finish.
 *
 * Greenlight runs all callbacks if one fails. A cleanup failure errors a passed
 * or skipped test. It does not replace an earlier test failure or error.
 */
final class Cleanup
{
    /** @var list<\Closure> */
    private array $callbacks = [];

    private bool $closed = false;

    /**
     * Register cleanup immediately after the test acquires a resource. This
     * makes cleanup available if a later operation fails.
     * Greenlight ignores the callback return value.
     *
     * @template TReturn
     *
     * @param \Closure(): TReturn $cleanup
     *
     * @throws \LogicException If test cleanup has started.
     */
    public function defer(\Closure $cleanup): void
    {
        if ($this->closed) {
            throw new \LogicException('Cleanup cannot be registered after test cleanup starts.');
        }

        $this->callbacks[] = $cleanup;
    }

    /**
     * Runs each callback and returns its failures.
     *
     * @internal
     *
     * @return list<\Throwable>
     */
    public function close(): array
    {
        if ($this->closed) {
            return [];
        }

        $this->closed = true;
        $callbacks = \array_reverse($this->callbacks);
        $this->callbacks = [];
        $failures = [];

        foreach ($callbacks as $callback) {
            try {
                $callback();
            } catch (\Throwable $failure) {
                $failures[] = $failure;
            }
        }

        return $failures;
    }
}

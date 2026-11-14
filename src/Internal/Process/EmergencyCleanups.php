<?php

declare(strict_types=1);

namespace Greenlight\Internal\Process;

/**
 * Holds the cleanups that must run before a forced exit.
 *
 * A second interrupt signal ends the process without `finally` blocks. Each
 * owner of an outside resource registers a cleanup while the resource exists.
 * The signal handler calls run() before the default signal action. Examples
 * are worker process groups, a child process group, and terminal modes.
 *
 * Each cleanup must be safe at any point of the normal control flow, because
 * an asynchronous signal can interrupt that flow.
 *
 * @internal
 */
final class EmergencyCleanups
{
    /** @var array<int, \Closure(): void> */
    private array $cleanups = [];

    private int $nextId = 0;

    /**
     * @param \Closure(): void $cleanup
     *
     * @return int The ID for unregister().
     */
    public function register(\Closure $cleanup): int
    {
        $id = $this->nextId++;
        $this->cleanups[$id] = $cleanup;

        return $id;
    }

    public function unregister(int $id): void
    {
        unset($this->cleanups[$id]);
    }

    /**
     * Runs each registered cleanup once, newest first. A failed cleanup does
     * not stop the cleanups after it.
     */
    public function run(): void
    {
        $cleanups = \array_reverse($this->cleanups);
        $this->cleanups = [];

        foreach ($cleanups as $cleanup) {
            try {
                $cleanup();
            } catch (\Throwable) {
                // The process exits next. No caller can handle the failure.
            }
        }
    }
}

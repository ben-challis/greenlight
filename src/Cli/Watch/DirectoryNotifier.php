<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * Reports events in a set of directories without a scan.
 *
 * @internal
 */
interface DirectoryNotifier
{
    /**
     * Makes the watched set equal to the specified directories.
     *
     * @param list<non-empty-string> $directories
     *
     * @return bool true when the call started a watch or could not start one.
     *   A file can change before its watch starts.
     *
     * @phpstan-impure
     */
    public function watch(array $directories): bool;

    /**
     * Reads all pending events.
     *
     * @return bool true when a watched directory reported an event since the
     *   previous call, or when the notifier cannot report events
     *
     * @phpstan-impure
     */
    public function changed(): bool;
}

<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

use Greenlight\Plugin\WatchSource;

/**
 * Runs the stat scan only after a directory notifier reports an event.
 *
 * poll() asks the notifier for events in the directories that the previous
 * scan listed. Without an event, it reports no changes and does not scan.
 * After an event, it runs the scan and gives the new directory list to the
 * notifier.
 *
 * A file can change between the scan and the start of a new watch. Thus, a
 * poll that starts a watch makes the next poll scan again.
 *
 * create() uses Linux inotify when it is available. Otherwise, it returns the
 * stat detector, which scans on each poll.
 *
 * @internal
 */
final class NotifiedChangeDetector implements WatchSource
{
    private bool $scanRequired = true;

    public function __construct(
        private readonly StatChangeDetector $scanner,
        private readonly DirectoryNotifier $notifier,
    ) {}

    public static function create(StatChangeDetector $scanner): WatchSource
    {
        $notifier = LinuxInotify::open();

        return $notifier instanceof LinuxInotify ? new self($scanner, $notifier) : $scanner;
    }

    #[\Override]
    public function poll(): array
    {
        $changed = $this->notifier->changed();

        if (!$changed && !$this->scanRequired) {
            return [];
        }

        $changes = $this->scanner->poll();
        $this->scanRequired = $this->notifier->watch($this->scanner->watchDirectories());

        return $changes;
    }
}

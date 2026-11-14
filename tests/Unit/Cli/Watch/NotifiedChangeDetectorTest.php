<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\DirectoryNotifier;
use Greenlight\Cli\Watch\NotifiedChangeDetector;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Doubles\Fake;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class NotifiedChangeDetectorTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function aPollWithoutAnEventDoesNotScan(): void
    {
        $root = $this->tempDirectory->subdirectory('notified-quiet');
        $file = $root . '/Example.php';
        \file_put_contents($file, 'first');
        $notifier = new ScriptedDirectoryNotifier();
        $detector = new NotifiedChangeDetector(new StatChangeDetector([$root]), $notifier);
        $detector->poll();
        \file_put_contents($file, 'second, longer');

        expect($detector->poll())
            ->because('only a notifier event starts a scan')
            ->toBe([]);

        $notifier->events = 1;

        expect($detector->poll())->toBe([$file]);
    }

    #[Test]
    public function eachScanGivesTheListedDirectoriesToTheNotifier(): void
    {
        $root = $this->tempDirectory->subdirectory('notified-directories');
        $notifier = new ScriptedDirectoryNotifier();
        $detector = new NotifiedChangeDetector(new StatChangeDetector([$root]), $notifier);
        $detector->poll();
        $nested = $this->tempDirectory->subdirectory('notified-directories/nested');
        $notifier->events = 1;
        $detector->poll();

        expect($notifier->watched)->toBe([[$root], [$root, $nested]]);
    }

    #[Test]
    public function aPollThatStartsAWatchMakesTheNextPollScan(): void
    {
        $root = $this->tempDirectory->subdirectory('notified-new-watch');
        $file = $root . '/Example.php';
        \file_put_contents($file, 'first');
        $notifier = new ScriptedDirectoryNotifier();
        $notifier->started = [true, false];
        $detector = new NotifiedChangeDetector(new StatChangeDetector([$root]), $notifier);
        $detector->poll();
        \file_put_contents($file, 'second, longer');

        expect($detector->poll())
            ->because('a file can change before its watch starts')
            ->toBe([$file]);
        \file_put_contents($file, 'third, longer still');
        expect($detector->poll())->toBe([]);
    }
}

final class ScriptedDirectoryNotifier implements DirectoryNotifier, Fake
{
    public int $events = 0;

    /** @var list<bool> */
    public array $started = [];

    /** @var list<list<non-empty-string>> */
    public array $watched = [];

    #[\Override]
    public function watch(array $directories): bool
    {
        $this->watched[] = $directories;

        return \array_shift($this->started) ?? false;
    }

    #[\Override]
    public function changed(): bool
    {
        if ($this->events === 0) {
            return false;
        }

        --$this->events;

        return true;
    }
}

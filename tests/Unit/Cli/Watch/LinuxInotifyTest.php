<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\LinuxInotify;
use Greenlight\Cli\Watch\NotifiedChangeDetector;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Condition\Condition;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

#[SkipUnless(LinuxInotifyAvailable::class)]
final readonly class LinuxInotifyTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function aWatchedDirectoryReportsAFileEventOnce(): void
    {
        $root = $this->tempDirectory->subdirectory('inotify-file');
        $notifier = $this->open();

        expect($notifier->watch([$root]))->toBeTrue();
        expect($notifier->watch([$root]))->toBeFalse();
        expect($notifier->changed())->toBeFalse();

        \file_put_contents($root . '/Example.php', 'first');

        expect($notifier->changed())->toBeTrue();
        expect($notifier->changed())->toBeFalse();
    }

    #[Test]
    public function anUnwatchedDirectoryReportsNoEvents(): void
    {
        $watched = $this->tempDirectory->subdirectory('inotify-unwatched/watched');
        $unwatched = $this->tempDirectory->subdirectory('inotify-unwatched/other');
        $notifier = $this->open();
        $notifier->watch([$watched, $unwatched]);
        $notifier->watch([$watched]);
        $notifier->changed();

        \file_put_contents($unwatched . '/Example.php', 'first');

        expect($notifier->changed())->toBeFalse();
    }

    #[Test]
    public function aRecreatedDirectoryGetsANewWatch(): void
    {
        $directory = $this->tempDirectory->subdirectory('inotify-recreated/source');
        $notifier = $this->open();
        $notifier->watch([$directory]);
        \rmdir($directory);
        $notifier->changed();
        \mkdir($directory);

        expect($notifier->watch([$directory]))
            ->because('the removed directory ended its watch')
            ->toBeTrue();

        \file_put_contents($directory . '/Example.php', 'first');

        expect($notifier->changed())->toBeTrue();
    }

    #[Test]
    public function aMissingDirectoryMakesTheNextPollScan(): void
    {
        $root = $this->tempDirectory->subdirectory('inotify-missing');
        $notifier = $this->open();

        expect($notifier->watch([$root . '/missing']))->toBeTrue();
        expect($notifier->changed())->toBeFalse();
    }

    #[Test]
    public function theDetectorFindsChangesInNewNestedDirectories(): void
    {
        $root = $this->tempDirectory->subdirectory('inotify-detector');
        $detector = new NotifiedChangeDetector(new StatChangeDetector([$root]), $this->open());
        $detector->poll();
        $detector->poll();

        expect($detector->poll())->toBe([]);

        \mkdir($root . '/nested');
        $file = $root . '/nested/Example.php';
        \file_put_contents($file, 'first');

        expect($detector->poll())->toBe([$file]);

        $detector->poll();
        \file_put_contents($file, 'second, longer');

        expect($detector->poll())->toBe([$file]);
    }

    #[Test]
    public function theDetectorFindsAMissingInputThatAppears(): void
    {
        $root = $this->tempDirectory->subdirectory('inotify-appears');
        $detector = new NotifiedChangeDetector(
            new StatChangeDetector([$root . '/tests']),
            $this->open(),
        );
        $detector->poll();
        $detector->poll();
        \mkdir($root . '/tests');
        $file = $root . '/tests/ExampleTest.php';
        \file_put_contents($file, 'first');

        $first = $detector->poll();
        $second = $detector->poll();

        expect([...$first, ...$second])->toBe([$file]);
    }

    private function open(): LinuxInotify
    {
        $notifier = LinuxInotify::open();

        if (!$notifier instanceof LinuxInotify) {
            throw new \RuntimeException('Linux inotify is not available through FFI.');
        }

        return $notifier;
    }
}

final readonly class LinuxInotifyAvailable implements Condition
{
    #[\Override]
    public function isSatisfied(): bool
    {
        return LinuxInotify::open() instanceof LinuxInotify;
    }
}

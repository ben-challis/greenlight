<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Cli\Watch\WatchPathMatcher;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class StatChangeDetectorWatchDirectoriesTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function listedDirectoriesExcludeExcludedTrees(): void
    {
        $root = $this->tempDirectory->subdirectory('watch-listed');
        $source = $this->tempDirectory->subdirectory('watch-listed/src');
        $this->tempDirectory->subdirectory('watch-listed/src/cache');
        $detector = new StatChangeDetector(
            [$source],
            matcher: new WatchPathMatcher($root, [], ['src/cache/**']),
        );

        $detector->poll();

        expect($detector->watchDirectories())->toBe([$source]);
    }

    #[Test]
    public function missingAndFileInputsListTheNearestExistingDirectory(): void
    {
        $root = $this->tempDirectory->subdirectory('watch-nearest');
        $config = $this->tempDirectory->subdirectory('watch-nearest/config');
        $detector = new StatChangeDetector(
            [$root . '/missing/tests'],
            additionalPaths: [$config . '/app.yaml'],
        );

        $detector->poll();

        expect($detector->watchDirectories())->toBe([$root, $config]);
    }

    #[Test]
    public function aSymlinkedInputListsItsParentDirectory(): void
    {
        $root = $this->tempDirectory->subdirectory('watch-linked-input');
        $target = $this->tempDirectory->subdirectory('watch-linked-target');
        $link = $root . '/linked';

        if (!\symlink($target, $link)) {
            throw new \RuntimeException('Could not create the watch symlink fixture.');
        }

        $detector = new StatChangeDetector([$link]);

        $detector->poll();

        expect($detector->watchDirectories())->toBe([$root]);
    }
}

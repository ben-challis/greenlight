<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Cli\Watch\WatchPathMatcher;
use Greenlight\Cli\Watch\WatchScanFailed;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\FilesystemRestriction;

final readonly class StatChangeDetectorTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function ignoresMissingDirectoriesAndNonPhpFiles(): void
    {
        $root = $this->tempDirectory->subdirectory('watch');
        $directory = $root . '/.';
        $textFile = $directory . '/notes.txt';
        \file_put_contents($textFile, 'first');

        $detector = new StatChangeDetector([
            $root . '/missing',
            $directory,
        ]);

        Expect::that($detector->poll())
            ->because('the initial scan records only existing PHP files')
            ->toBe([]);

        \file_put_contents($textFile, 'changed');

        Expect::that($detector->poll())
            ->because('missing directories and non-PHP changes MUST be ignored')
            ->toBe([]);
    }

    #[Test]
    public function additionalDirectoriesUseIncludesAndExcludePrecedence(): void
    {
        $root = $this->tempDirectory->subdirectory('watch-additional');
        $templates = $this->tempDirectory->subdirectory('watch-additional/templates');
        $cache = $this->tempDirectory->subdirectory('watch-additional/templates/cache');
        $included = $templates . '/page.twig';
        $excluded = $cache . '/page.twig';
        $unmatched = $templates . '/notes.txt';
        \file_put_contents($included, 'first');
        \file_put_contents($excluded, 'first');
        \file_put_contents($unmatched, 'first');
        $detector = new StatChangeDetector(
            [],
            additionalPaths: [$templates],
            matcher: new WatchPathMatcher($root, ['**/*.twig'], ['templates/cache/**']),
        );
        $detector->poll();

        \file_put_contents($included, 'second');
        \file_put_contents($excluded, 'second');
        \file_put_contents($unmatched, 'second');

        Expect::that($detector->poll())
            ->because('exclusions MUST have precedence and unmatched files MUST not be hashed')
            ->toHaveCount(1);
        Expect::that($detector->poll())->toBe([]);
    }

    #[Test]
    public function exactAdditionalFilesNeedNoIncludePattern(): void
    {
        $root = $this->tempDirectory->subdirectory('watch-exact-input');
        $file = $root . '/settings.json';
        \file_put_contents($file, 'first');
        $detector = new StatChangeDetector(
            [],
            additionalPaths: [$file],
            matcher: new WatchPathMatcher($root, ['**/*.yaml'], []),
        );
        $detector->poll();
        \file_put_contents($file, 'second');

        Expect::that($detector->poll())->toBe([$file]);
    }

    #[Test]
    public function missingAdditionalInputsCanAppearDisappearAndReappear(): void
    {
        $root = $this->tempDirectory->subdirectory('watch-missing-input');
        $file = $root . '/settings.yaml';
        $detector = new StatChangeDetector([], additionalPaths: [$file]);
        $detector->poll();

        \file_put_contents($file, 'first');
        $created = $detector->poll();
        \unlink($file);
        $removed = $detector->poll();
        \file_put_contents($file, 'second');
        $recreated = $detector->poll();

        Expect::that($created)->toBe([$file]);
        Expect::that($removed)->toBe([$file]);
        Expect::that($recreated)->toBe([$file]);
    }

    #[Test]
    public function aRenameReportsOneCreatedPathAndOneRemovedPath(): void
    {
        $root = $this->tempDirectory->subdirectory('watch-rename');
        $old = $root . '/old.yaml';
        $new = $root . '/new.yaml';
        \file_put_contents($old, 'contents');
        $detector = new StatChangeDetector([], additionalPaths: [$root]);
        $detector->poll();
        \rename($old, $new);

        $changes = $detector->poll();

        Expect::that($changes)->toBe([$new, $old]);
    }

    #[Test]
    public function directorySymlinksAreNotFollowed(): void
    {
        $root = $this->tempDirectory->subdirectory('watch-symlink');
        $target = $this->tempDirectory->subdirectory('watch-symlink-target');
        $link = $root . '/linked';
        $file = $target . '/settings.yaml';
        \file_put_contents($file, 'first');

        if (!\symlink($target, $link)) {
            throw new \RuntimeException('Could not create the watch symlink fixture.');
        }

        $detector = new StatChangeDetector([], additionalPaths: [$root]);
        $detector->poll();
        \file_put_contents($file, 'second');

        Expect::that($detector->poll())
            ->because('watch polling MUST not follow directory symlinks')
            ->toBe([]);
    }

    #[Test]
    public function stopsDeterministicallyAtTheConfiguredFileLimit(): void
    {
        $root = $this->tempDirectory->subdirectory('watch-limit');
        \file_put_contents($root . '/a.yaml', 'a');
        \file_put_contents($root . '/b.yaml', 'b');
        $detector = new StatChangeDetector([], additionalPaths: [$root], maximumFiles: 1);

        Expect::that($detector->poll(...))
            ->toThrow(
                WatchScanFailed::class,
                message: 'Watch mode matched more files than the limit of 1. Narrow the watch paths or patterns, or increase maximumFiles().',
            );
    }

    #[Test]
    #[Isolated]
    public function aRestrictedDirectoryIsIgnoredWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 4);
        $directory = \dirname($root);
        FilesystemRestriction::toProject($root);

        $detector = new StatChangeDetector([$directory]);
        $changed = ErrorTrap::run(static fn() => $detector->poll(), $warning);

        Expect::that($changed)
            ->because('a restricted watch directory MUST behave as a missing directory')
            ->toBe([]);
        Expect::that($warning)
            ->because('a restricted watch directory MUST not leak engine diagnostics')
            ->toBeNull();
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Fixture\TempDirectoryError;
use Greenlight\Tests\Support\Subprocess;

final class TempDirectoryTest
{
    #[Test]
    public function nothingExistsOnDiskBeforeFirstUse(): void
    {
        // path() is the only method that accesses the disk. Construction does
        // not create a directory for disposal.
        $directory = new TempDirectory();

        Expect::that(static function () use ($directory): void {
            $directory->dispose();
        })->because('nothing exists on disk before first use')->not()->toThrow(\Throwable::class);
    }

    #[Test]
    public function pathCreatesAWritableDirectoryAndMemoizesIt(): void
    {
        $directory = new TempDirectory();

        $path = $directory->path();

        Expect::that(\is_dir($path))->because('path creates a writable directory and memoizes it')->toBeTrue();
        Expect::that(\is_writable($path))->toBeTrue();
        Expect::that(\realpath($path))->toBe($path);
        Expect::that($directory->path())->toBe($path);

        $directory->dispose();
    }

    #[Test]
    public function aTemporaryRootCannotContainANullByte(): void
    {
        Expect::that(static fn(): TempDirectory => new TempDirectory("root\0suffix"))
            ->because('the fixture MUST reject an invalid temporary root before a file-system operation')
            ->toThrow(
                \InvalidArgumentException::class,
                message: 'Temporary root MUST NOT contain a null byte.',
            );
    }

    #[Test]
    public function aBlockedTempRootGivesExactGuidance(): void
    {
        $directory = new TempDirectory();
        $blockedRoot = $directory->path() . '/blocked-root';

        if (\file_put_contents($blockedRoot, 'not a directory') === false) {
            Fail::because('Expected to create a file at the temporary root path.');
        }

        try {
            $root = \dirname(__DIR__, 3);
            $result = Subprocess::run(
                $root,
                [
                    \PHP_BINARY,
                    '-r',
                    <<<'PHP'
                        require $argv[1];

                        try {
                            new Greenlight\Fixture\TempDirectory()->path();
                        } catch (Greenlight\Fixture\TempDirectoryError $error) {
                            fwrite(STDOUT, $error->getMessage());
                            exit(23);
                        }
                        PHP,
                    $root . '/vendor/autoload.php',
                ],
                [
                    'TEMP' => $blockedRoot,
                    'TMP' => $blockedRoot,
                    'TMPDIR' => $blockedRoot,
                    'XDEBUG_MODE' => 'off',
                ],
            );

            Expect::that($result->exitCode)
                ->because('a blocked temp root MUST fail directory creation')
                ->toBe(23);
            Expect::that($result->stdout)
                ->because('the failure MUST identify the generated directory and cause')
                ->toMatch(
                    '/\AFailed to create temp directory "'
                    . \preg_quote($blockedRoot, '/')
                    . '\/greenlight-[a-f0-9]{16}": mkdir\(\): Not a directory\.\z/',
                );
        } finally {
            $directory->dispose();
        }
    }

    #[Test]
    public function twoInstancesGetDistinctPaths(): void
    {
        $first = new TempDirectory();
        $second = new TempDirectory();

        Expect::that($first->path())->because('two instances get distinct paths')->not()->toBe($second->path());

        $first->dispose();
        $second->dispose();
    }

    #[Test]
    public function subdirectoryCreatesNestedDirectories(): void
    {
        $directory = new TempDirectory();

        $nested = $directory->subdirectory('a/b');

        Expect::that($nested)->because('subdirectory creates nested directories')->toBe($directory->path() . '/a/b');
        Expect::that(\is_dir($nested))->toBeTrue();

        $directory->dispose();
    }

    #[Test]
    public function anExistingFileBlocksANestedSubdirectoryWithTheFullTarget(): void
    {
        $directory = new TempDirectory();
        $blocked = $directory->path() . '/blocked';
        \file_put_contents($blocked, 'not a directory');
        $target = $blocked . '/nested';

        Expect::that(static fn(): string => $directory->subdirectory('blocked/nested'))
            ->because('an existing file blocks a nested subdirectory with the full target')
            ->toThrow(
                TempDirectoryError::class,
                message: \sprintf(
                    'Failed to create subdirectory "%s": mkdir(): Not a directory.',
                    $target,
                ),
            );

        $directory->dispose();
    }

    #[Test]
    #[DataSet('invalidSubdirectoryNames')]
    public function subdirectoryRejectsUnsafePaths(string $name, string $expectedMessage): void
    {
        $directory = new TempDirectory();

        Expect::that(static fn(): string => $directory->subdirectory($name))
            ->because('subdirectory rejects unsafe paths')
            ->toThrow(\InvalidArgumentException::class, message: $expectedMessage);

        $directory->dispose();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidSubdirectoryNames(): iterable
    {
        yield 'leading traversal' => [
            '../escape',
            'Subdirectory name "../escape" must not contain empty or traversal segments.',
        ];
        yield 'embedded traversal' => [
            'a/../b',
            'Subdirectory name "a/../b" must not contain empty or traversal segments.',
        ];
        yield 'absolute path' => [
            '/absolute',
            'Subdirectory name "/absolute" must be a relative path.',
        ];
        yield 'empty path' => [
            '',
            'Subdirectory name "" must be a relative path.',
        ];
        yield 'Windows separator' => [
            'a\\b',
            'Subdirectory name "a\b" must be a relative path.',
        ];
        yield 'empty segment' => [
            'a//b',
            'Subdirectory name "a//b" must not contain empty or traversal segments.',
        ];
        yield 'current-directory segment' => [
            'a/./b',
            'Subdirectory name "a/./b" must not contain empty or traversal segments.',
        ];
        yield 'null byte' => [
            "a\0b",
            'Subdirectory name MUST NOT contain a null byte.',
        ];
    }

    #[Test]
    public function disposeRemovesTheDirectoryIncludingNestedFiles(): void
    {
        $directory = new TempDirectory();
        $path = $directory->path();
        $nested = $directory->subdirectory('deep/inner');
        \file_put_contents($path . '/top.txt', 'top');
        \file_put_contents($nested . '/leaf.txt', 'leaf');

        $directory->dispose();

        Expect::that(\file_exists($path))->because('dispose removes the directory including nested files')->toBeFalse();
    }

    #[Test]
    public function disposeReportsAnEntryThatItCannotRemove(): void
    {
        $directory = new TempDirectory();
        $path = $directory->path();
        $file = $path . '/locked.txt';
        \file_put_contents($file, 'keep');
        \chmod($path, 0o500);
        \clearstatcache(true, $path);

        try {
            if (\is_writable($path)) {
                throw new SkipTest('The filesystem does not enforce directory write permissions.');
            }

            Expect::that(static function () use ($directory): void {
                $directory->dispose();
            })
                ->because('fixture cleanup MUST report the entry that it cannot remove')
                ->toThrow(
                    TempDirectoryError::class,
                    message: \sprintf(
                        'Failed to remove "%s" while disposing temp directory "%s".',
                        $file,
                        $path,
                    ),
                );
        } finally {
            \chmod($path, 0o700);
            $directory->dispose();
        }
    }

    #[Test]
    public function disposeReportsItsRootWhenTheRootCannotBeRemoved(): void
    {
        $owner = new TempDirectory();
        $temporaryRoot = $owner->subdirectory('blocked-root');
        $directory = new TempDirectory($temporaryRoot);
        $path = $directory->path();
        \chmod($temporaryRoot, 0o500);
        \clearstatcache(true, $temporaryRoot);

        try {
            if (\is_writable($temporaryRoot)) {
                throw new SkipTest('The filesystem does not enforce directory write permissions.');
            }

            Expect::that(static function () use ($directory): void {
                $directory->dispose();
            })
                ->because('fixture cleanup MUST report a root that it cannot remove')
                ->toThrow(
                    TempDirectoryError::class,
                    // PHP 8.5 includes the path argument.
                    // Remove the PHP 8.5 form when PHP 8.6 is the minimum version.
                    matching: \sprintf(
                        '/^Failed to remove temp directory "%s": rmdir\((?:%s)?\): Permission denied\.$/',
                        \preg_quote($path, '/'),
                        \preg_quote($path, '/'),
                    ),
                );
        } finally {
            \chmod($temporaryRoot, 0o700);
            $directory->dispose();
            $owner->dispose();
        }
    }

    #[Test]
    public function disposeRemovesASymbolicLinkWithoutChangingItsTarget(): void
    {
        $directory = new TempDirectory();
        $target = new TempDirectory();
        $sentinel = $target->path() . '/sentinel.txt';
        $link = $directory->path() . '/linked-target';
        \file_put_contents($sentinel, 'keep');

        try {
            if (!\symlink($target->path(), $link)) {
                Fail::because('Expected symlink() to create the fixture link.');
            }

            $directory->dispose();

            Expect::that(\is_link($link))
                ->because('disposal MUST remove the symbolic link')
                ->toBeFalse();
            Expect::that(\is_dir($target->path()))
                ->because('disposal MUST leave the symbolic link target unchanged')
                ->toBeTrue();
            Expect::that(\file_get_contents($sentinel))
                ->toBe('keep');
        } finally {
            $directory->dispose();
            $target->dispose();
        }
    }

    #[Test]
    public function disposeWithoutUseIsANoOp(): void
    {
        $directory = new TempDirectory();
        $directory->dispose();

        // A no-op dispose() MUST NOT keep a stale or missing path. A later
        // path() call still creates a new writable directory.
        $path = $directory->path();

        Expect::that(\is_dir($path))->because('dispose without use is a no-op')->toBeTrue();
        Expect::that(\is_writable($path))->toBeTrue();

        $directory->dispose();
    }
}

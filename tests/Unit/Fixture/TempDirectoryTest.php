<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;

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

        Expect::that(\is_dir($path))->because('path creates a writable directory and memoizes it')->toBeTrue()
            ->and(\is_writable($path))->toBeTrue()
            ->and(\realpath($path))->toBe($path)
            ->and($directory->path())->toBe($path);

        $directory->dispose();
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

        Expect::that($nested)->because('subdirectory creates nested directories')->toBe($directory->path() . '/a/b')
            ->and(\is_dir($nested))->toBeTrue();

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
                \RuntimeException::class,
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
                ->toBeFalse()
                ->and(\is_dir($target->path()))
                ->because('disposal MUST leave the symbolic link target unchanged')
                ->toBeTrue()
                ->and(\file_get_contents($sentinel))
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

        Expect::that(\is_dir($path))->because('dispose without use is a no-op')->toBeTrue()
            ->and(\is_writable($path))->toBeTrue();

        $directory->dispose();
    }
}

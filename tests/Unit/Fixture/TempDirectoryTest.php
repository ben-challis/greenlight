<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
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
    public function subdirectoryRejectsTraversalAndAbsolutePaths(): void
    {
        $directory = new TempDirectory();

        Expect::that(static fn(): string => $directory->subdirectory('../escape'))->because('subdirectory rejects traversal and absolute paths')
            ->toThrow(\InvalidArgumentException::class)
            ->and(static fn(): string => $directory->subdirectory('a/../b'))
            ->toThrow(\InvalidArgumentException::class)
            ->and(static fn(): string => $directory->subdirectory('/absolute'))
            ->toThrow(\InvalidArgumentException::class)
            ->and(static fn(): string => $directory->subdirectory(''))
            ->toThrow(\InvalidArgumentException::class);

        $directory->dispose();
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

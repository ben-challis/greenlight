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
        $directory = new TempDirectory();

        $property = new \ReflectionProperty(TempDirectory::class, 'path');

        Expect::that($property->getValue($directory))->toBeNull();
    }

    #[Test]
    public function pathCreatesAWritableDirectoryAndMemoizesIt(): void
    {
        $directory = new TempDirectory();

        $path = $directory->path();

        Expect::that(\is_dir($path))->toBeTrue()
            ->and(\is_writable($path))->toBeTrue()
            ->and($directory->path())->toBe($path);

        $directory->dispose();
    }

    #[Test]
    public function twoInstancesGetDistinctPaths(): void
    {
        $first = new TempDirectory();
        $second = new TempDirectory();

        Expect::that($first->path())->not()->toBe($second->path());

        $first->dispose();
        $second->dispose();
    }

    #[Test]
    public function subdirectoryCreatesNestedDirectories(): void
    {
        $directory = new TempDirectory();

        $nested = $directory->subdirectory('a/b');

        Expect::that($nested)->toBe($directory->path() . '/a/b')
            ->and(\is_dir($nested))->toBeTrue();

        $directory->dispose();
    }

    #[Test]
    public function subdirectoryRejectsTraversalAndAbsolutePaths(): void
    {
        $directory = new TempDirectory();

        Expect::that(static fn(): string => $directory->subdirectory('../escape'))
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

        Expect::that(\file_exists($path))->toBeFalse();
    }

    #[Test]
    public function disposeWithoutUseIsANoOp(): void
    {
        $directory = new TempDirectory();

        $directory->dispose();

        $property = new \ReflectionProperty(TempDirectory::class, 'path');

        Expect::that($property->getValue($directory))->toBeNull();
    }
}

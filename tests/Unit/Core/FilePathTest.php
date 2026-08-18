<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\FilePath;
use Greenlight\Expect\Expect;

final readonly class FilePathTest
{
    #[Test]
    #[DataSet('paths')]
    public function recognizesAbsolutePathsAcrossPlatforms(
        string $path,
        string $directorySeparator,
        bool $absolute,
    ): void {
        Expect::that(FilePath::isAbsolute($path, $directorySeparator))
            ->because('absolute path detection MUST distinguish rooted paths from relative paths')
            ->toBe($absolute);
    }

    /**
     * @return iterable<string, array{string, string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'POSIX root' => ['/', '/', true];
        yield 'POSIX path' => ['/project/src', '/', true];
        yield 'Windows drive with backslashes' => ['C:\\project\\src', '\\', true];
        yield 'Windows drive with slashes' => ['d:/project/src', '\\', true];
        yield 'Windows root-relative path' => ['\\project\\src', '\\', true];
        yield 'Windows UNC path' => ['\\\\server\\share\\src', '\\', true];
        yield 'empty POSIX path' => ['', '/', false];
        yield 'empty Windows path' => ['', '\\', false];
        yield 'relative path' => ['project/src', '/', false];
        yield 'Windows drive-relative path' => ['C:project\\src', '\\', false];
        yield 'nonletter drive prefix' => ['1:\\project\\src', '\\', false];
        yield 'Windows path on POSIX' => ['C:\\project\\src', '/', false];
        yield 'backslash path on POSIX' => ['\\project\\src', '/', false];
    }

    #[Test]
    #[DataSet('resolvedPaths')]
    public function resolvesRelativePathsAndPreservesAbsolutePaths(
        string $path,
        string $workingDirectory,
        string $directorySeparator,
        string $resolved,
    ): void {
        Expect::that(FilePath::resolve($path, $workingDirectory, $directorySeparator))
            ->because('path resolution MUST join only relative paths to the working directory')
            ->toBe($resolved);
    }

    /**
     * @return iterable<string, array{string, string, string, string}>
     */
    public static function resolvedPaths(): iterable
    {
        yield 'relative POSIX path' => ['tests/Unit', '/project', '/', '/project/tests/Unit'];
        yield 'relative POSIX path from root' => ['tests/Unit', '/', '/', '/tests/Unit'];
        yield 'absolute POSIX path' => ['/tests/Unit', '/project', '/', '/tests/Unit'];
        yield 'relative Windows path' => ['tests\\Unit', 'C:\\project', '\\', 'C:\\project\\tests\\Unit'];
        yield 'drive-rooted Windows path' => ['D:\\tests', 'C:\\project', '\\', 'D:\\tests'];
        yield 'root-relative Windows path' => ['\\tests', 'C:\\project', '\\', '\\tests'];
        yield 'UNC Windows path' => ['\\\\server\\tests', 'C:\\project', '\\', '\\\\server\\tests'];
    }
}

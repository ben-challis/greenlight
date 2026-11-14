<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Filesystem;

use Greenlight\Attribute\Test;
use Greenlight\Internal\Filesystem\DirectoryWalkError;
use Greenlight\Internal\Filesystem\DirectoryWalkFailure;
use Greenlight\Internal\Filesystem\SafeDirectory;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class SafeDirectoryTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function createMakesMissingDirectoriesFromTheTopDown(): void
    {
        $base = $this->tempDirectory->subdirectory('safe-directory-create');

        expect(SafeDirectory::check($base . '/a/b'))->toBe([$base . '/a', $base . '/a/b']);
        SafeDirectory::create($base . '/a/b');

        expect(\is_dir($base . '/a/b'))->toBeTrue();
        expect(\fileperms($base . '/a/b') & 0o777)->toBe(0o700);
    }

    #[Test]
    public function aRootLimitsTheWalkAndChecksEachExistingPath(): void
    {
        $base = $this->tempDirectory->subdirectory('safe-directory-root');
        $target = $this->tempDirectory->subdirectory('safe-directory-root-target');
        \mkdir($base . '/run');
        \symlink($target, $base . '/run/linked');

        expect(SafeDirectory::check($base . '/run/missing', $base . '/run'))->toBe([$base . '/run/missing']);
        expect(SafeDirectory::check('/outside', $base . '/run'))
            ->because('a walk with a root MUST ignore paths outside the root')
            ->toBe([]);
        expect($this->failure(static fn() => SafeDirectory::check($base . '/run/linked/child', $base . '/run')))
            ->toBe(DirectoryWalkFailure::SymbolicLink);
    }

    #[Test]
    public function linksAndFilesStopTheWalk(): void
    {
        $base = $this->tempDirectory->subdirectory('safe-directory-failures');
        $target = $this->tempDirectory->subdirectory('safe-directory-failures-target');
        \symlink($target, $base . '/linked');
        \file_put_contents($base . '/file', 'file');

        expect($this->failure(static fn() => SafeDirectory::create($base . '/linked')))
            ->because('a walk MUST NOT follow a symbolic link')
            ->toBe(DirectoryWalkFailure::SymbolicLink);
        expect($this->failure(static fn() => SafeDirectory::create($base . '/file/child')))
            ->toBe(DirectoryWalkFailure::NotADirectory);
    }

    /** @param \Closure(): mixed $walk */
    private function failure(\Closure $walk): ?DirectoryWalkFailure
    {
        try {
            $walk();
        } catch (DirectoryWalkError $error) {
            return $error->failure;
        }

        return null;
    }
}

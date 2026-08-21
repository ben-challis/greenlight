<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Sandbox;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Sandbox\TemporaryDirectoryError;

final class TemporaryDirectoryRootSymbolicLinkTest
{
    #[Test]
    public function disposalDoesNotFollowAReplacedRootSymbolicLink(): void
    {
        $directory = new TemporaryDirectory();
        $target = new TemporaryDirectory();
        $root = $directory->path();
        $sentinel = $target->path() . '/sentinel.txt';

        if (\file_put_contents($sentinel, 'keep') === false) {
            Fail::because('Expected to create the target sentinel file.');
        }

        if (!\rmdir($root) || !\symlink($target->path(), $root)) {
            Fail::because('Expected to replace the temp directory root with a symbolic link.');
        }

        try {
            Expect::that(static fn() => $directory->dispose())
                ->because('disposal MUST remove the root symbolic link without entry traversal')
                ->not()
                ->toThrow(\Throwable::class);

            Expect::that(\is_link($root))
                ->because('disposal MUST remove the root symbolic link')
                ->toBeFalse();
            Expect::that(\file_get_contents($sentinel))
                ->because('disposal MUST leave the root symbolic link target unchanged')
                ->toBe('keep');
        } finally {
            if (\is_link($root)) {
                \unlink($root);
            }

            $directory->dispose();
            $target->dispose();
        }
    }

    #[Test]
    public function disposalReportsARootSymbolicLinkThatCannotBeRemoved(): void
    {
        $owner = new TemporaryDirectory();
        $temporaryRoot = $owner->subdirectory('blocked-root');
        $directory = new TemporaryDirectory($temporaryRoot);
        $target = new TemporaryDirectory();
        $root = $directory->path();

        if (!\rmdir($root) || !\symlink($target->path(), $root)) {
            Fail::because('Expected to replace the temp directory root with a symbolic link.');
        }

        \chmod($temporaryRoot, 0o500);
        \clearstatcache(true, $temporaryRoot);

        try {
            if (\is_writable($temporaryRoot)) {
                throw new SkipTest('The filesystem does not enforce directory write permissions.');
            }

            Expect::that(static fn() => $directory->dispose())
                ->because('fixture cleanup MUST report a root symbolic link that it cannot remove')
                ->toThrow(
                    TemporaryDirectoryError::class,
                    // PHP 8.5 includes the path argument.
                    // Remove the PHP 8.5 form when PHP 8.6 is the minimum version.
                    matching: \sprintf(
                        '/^Failed to remove temp directory symbolic link "%s": unlink\((?:%s)?\): Permission denied\.$/',
                        \preg_quote($root, '/'),
                        \preg_quote($root, '/'),
                    ),
                );
        } finally {
            \chmod($temporaryRoot, 0o700);

            if (\is_link($root)) {
                \unlink($root);
            }

            $directory->dispose();
            $target->dispose();
            $owner->dispose();
        }
    }
}

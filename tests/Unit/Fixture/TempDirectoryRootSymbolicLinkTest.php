<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;

final class TempDirectoryRootSymbolicLinkTest
{
    #[Test]
    public function disposalDoesNotFollowAReplacedRootSymbolicLink(): void
    {
        $directory = new TempDirectory();
        $target = new TempDirectory();
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
                ->toBeFalse()
                ->and(\file_get_contents($sentinel))
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
}

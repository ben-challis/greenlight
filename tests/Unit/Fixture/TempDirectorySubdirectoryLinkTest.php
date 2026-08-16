<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;

final class TempDirectorySubdirectoryLinkTest
{
    #[Test]
    #[DataSet('linkedPaths')]
    public function subdirectoryRejectsPathsThroughSymbolicLinks(?string $linkName, string $name): void
    {
        $directory = new TempDirectory();
        $target = new TempDirectory();
        $root = $directory->path();
        $targetPath = $target->path();
        $sentinel = $targetPath . '/sentinel.txt';
        $link = $linkName === null ? $root : $root . '/' . $linkName;

        if (\file_put_contents($sentinel, 'keep') === false) {
            Fail::because('Expected to create the target sentinel file.');
        }

        if ($linkName === null && !\rmdir($root)) {
            Fail::because('Expected to remove the temp directory root.');
        }

        if (!\symlink($targetPath, $link)) {
            Fail::because('Expected to create the fixture symbolic link.');
        }

        try {
            Expect::that(static fn(): string => $directory->subdirectory($name))
                ->because('a subdirectory MUST remain inside its temp directory')
                ->toThrow(
                    \RuntimeException::class,
                    message: \sprintf(
                        'Subdirectory path "%s" contains a symbolic link.',
                        $link,
                    ),
                );

            Expect::that(\file_get_contents($sentinel))
                ->because('a rejected symbolic link MUST leave its target unchanged')
                ->toBe('keep');
            Expect::that(\is_dir($targetPath . '/created'))
                ->toBeFalse();
        } finally {
            $directory->dispose();
            $target->dispose();
        }
    }

    /**
     * @return iterable<string, array{?string, non-empty-string}>
     */
    public static function linkedPaths(): iterable
    {
        yield 'replaced root' => [null, 'created'];
        yield 'final segment' => ['linked', 'linked'];
        yield 'intermediate segment' => ['linked', 'linked/created'];
    }
}

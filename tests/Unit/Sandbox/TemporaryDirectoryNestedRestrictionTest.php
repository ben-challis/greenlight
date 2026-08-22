<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Sandbox;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Sandbox\TemporaryDirectoryError;
use Greenlight\Test\SkipTest;

final readonly class TemporaryDirectoryNestedRestrictionTest
{
    public function __construct(private TemporaryDirectory $directory) {}

    #[Test]
    public function unreadableNestedDirectoryProducesATypedCleanupError(): void
    {
        $directory = $this->directory;
        $root = $directory->path();
        $nested = $directory->subdirectory('restricted');

        if (\file_put_contents($nested . '/evidence.txt', 'keep') === false || !\chmod($nested, 0o000)) {
            Fail::because('Expected to create an unreadable nested directory.');
        }

        \clearstatcache(true, $nested);

        try {
            if (\is_readable($nested)) {
                throw new SkipTest('The filesystem does not enforce directory read permissions.');
            }

            $warning = null;
            Expect::that(static function () use ($directory, &$warning): void {
                ErrorTrap::run(static fn() => $directory->dispose(), $warning);
            })
                ->because('fixture cleanup MUST translate directory traversal failures')
                ->toThrow(
                    TemporaryDirectoryError::class,
                    matching: \sprintf(
                        '/^Failed to remove temp directory "%s": .*%s.*Permission denied\.$/',
                        \preg_quote($root, '/'),
                        \preg_quote($nested, '/'),
                    ),
                );
            Expect::that($warning)
                ->because('a nested traversal failure MUST not leak an engine diagnostic')
                ->toBeNull();
        } finally {
            \chmod($nested, 0o700);
            $directory->dispose();
        }
    }
}

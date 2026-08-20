<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\Test;
use Greenlight\Core\ErrorTrap;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Fixture\TempDirectoryError;

final readonly class TempDirectoryNestedRestrictionTest
{
    public function __construct(private TempDirectory $directory) {}

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
                    TempDirectoryError::class,
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

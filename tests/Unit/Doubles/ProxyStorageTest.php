<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\Doubles\ProxyStorageContract;

final readonly class ProxyStorageTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aFileThatBlocksTheProxyDirectoryGivesExactGuidance(): void
    {
        $directory = $this->tempDirectory->subdirectory('blocked-proxy-directory') . '/proxies';

        if (\file_put_contents($directory, 'occupied') === false) {
            Fail::because('Expected to create a file at the proxy directory path.');
        }

        $doubles = new Doubles($directory);

        Expect::that(static fn(): object => $doubles->stub(ProxyStorageContract::class))
            ->because('a file that blocks the proxy directory MUST produce a typed storage error')
            ->toThrow(
                DoublesError::class,
                message: 'Doubles could not create the proxy directory '
                    . $directory
                    . ': mkdir(): File exists.',
            );
    }
}

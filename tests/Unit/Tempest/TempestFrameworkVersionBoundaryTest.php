<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Tempest;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Tempest\TempestBridgeError;
use Greenlight\Tempest\TempestFrameworkRequirement;

final readonly class TempestFrameworkVersionBoundaryTest
{
    #[Test]
    #[DataSet('supportedVersions')]
    public function acceptsSupportedTempestThreeVersions(string $version): void
    {
        TempestFrameworkRequirement::checkVersion($version);

        Expect::that($version)
            ->because('the supported Tempest version MUST be 3.18 or later in major version 3')
            ->toMatch('/^3\.(?:1[89]|[2-9][0-9]|[1-9][0-9]{2,})(?:\.|$)/D');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function supportedVersions(): iterable
    {
        yield 'minimum release' => ['3.18.0'];
        yield 'later minor' => ['3.20.1'];
        yield 'later patch' => ['3.18.9'];
    }

    #[Test]
    #[DataSet('unsupportedVersions')]
    public function rejectsVersionsWithoutTheRequiredLifecycle(string $version): void
    {
        Expect::that(static function () use ($version): void {
            TempestFrameworkRequirement::checkVersion($version);
        })
            ->because('the Tempest bridge MUST require the verified long-running lifecycle')
            ->toThrow(
                TempestBridgeError::class,
                message: \sprintf(
                    'TempestPlugin found tempest/framework "%s", but it requires version 3.18 or later '
                    . 'in major version 3. Install tempest/framework ^3.18.',
                    $version,
                ),
            );
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function unsupportedVersions(): iterable
    {
        yield 'previous minor' => ['3.17.9'];
        yield 'minimum prerelease' => ['3.18.0-beta.1'];
        yield 'next major' => ['4.0.0'];
    }
}

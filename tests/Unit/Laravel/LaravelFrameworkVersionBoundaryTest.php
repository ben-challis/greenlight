<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Laravel;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Laravel\LaravelBridgeError;
use Greenlight\Laravel\LaravelFrameworkRequirement;

final readonly class LaravelFrameworkVersionBoundaryTest
{
    #[Test]
    #[DataSet('supportedVersions')]
    public function acceptsMajorVersionThirteen(string $version): void
    {
        LaravelFrameworkRequirement::checkVersion($version);

        Expect::that($version)
            ->because('the supported Laravel version MUST use major version 13')
            ->toMatch('/^13(?:\\.|$)/D');
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function supportedVersions(): iterable
    {
        yield 'bare major' => ['13'];
        yield 'release' => ['13.0.0'];
        yield 'pre-release' => ['13.1.0-beta.1'];
    }

    #[Test]
    #[DataSet('unsupportedVersions')]
    public function rejectsVersionsOutsideMajorVersionThirteen(string $version): void
    {
        Expect::that(static function () use ($version): void {
            LaravelFrameworkRequirement::checkVersion($version);
        })
            ->because('the Laravel bridge MUST reject versions outside major version 13')
            ->toThrow(
                LaravelBridgeError::class,
                message: \sprintf(
                    'LaravelPlugin found laravel/framework "%s", but it requires major version 13. '
                    . 'Install laravel/framework 13.',
                    $version,
                ),
            );
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function unsupportedVersions(): iterable
    {
        yield 'previous major' => ['12.99.99'];
        yield 'next major' => ['14.0.0'];
        yield 'numeric prefix' => ['130.0.0'];
        yield 'suffix without separator' => ['13beta'];
    }
}

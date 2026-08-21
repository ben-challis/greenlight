<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Core\ErrorTrap;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Tests\Fixture\Filesystem\StatableFileStream;

final readonly class ConfigLoaderOpenFailureTest
{
    private const string SCHEME = 'greenlight-config-open-failure';

    public function __construct(private StreamWrappers $streamWrappers) {}

    #[Test]
    public function openFailureIsWrappedWithoutEngineDiagnostics(): void
    {
        $this->streamWrappers->register(self::SCHEME, StatableFileStream::class);
        $file = self::SCHEME . '://greenlight.php';

        Expect::that(
            static function () use ($file, &$warning): void {
                ErrorTrap::run(
                    static fn() => new ConfigLoader()->loadFile($file),
                    $warning,
                );
            },
        )
            ->because('a configuration open failure MUST become only a configuration error')
            ->toThrow(
                static function (ConfigFileError $error) use ($file): void {
                    Expect::that($error->getMessage())
                        ->toContain(\sprintf('Configuration file "%s" threw Error:', $file));
                    Expect::that($error->getPrevious())
                        ->toBeInstanceOf(\Error::class);
                },
            );
        Expect::that($warning)
            ->because('a configuration open failure MUST not leak an engine diagnostic')
            ->toBeNull();
    }
}

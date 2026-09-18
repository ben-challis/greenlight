<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Tests\Fixture\Filesystem\StatableFileStream;

use function Greenlight\expect;

final readonly class ConfigLoaderOpenFailureTest
{
    private const string SCHEME = 'greenlight-config-open-failure';

    public function __construct(private StreamWrappers $streamWrappers) {}

    #[Test]
    public function openFailureIsWrappedWithoutEngineDiagnostics(): void
    {
        $this->streamWrappers->register(self::SCHEME, StatableFileStream::class);
        $file = self::SCHEME . '://greenlight.php';

        expect()->calling(
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
                    expect($error->getMessage())
                        ->toContain(\sprintf('Configuration file "%s" threw Error:', $file));
                    expect($error->getPrevious())
                        ->toBeInstanceOf(\Error::class);
                },
            );
        expect($warning)
            ->because('a configuration open failure MUST not leak an engine diagnostic')
            ->toBeNull();
    }
}

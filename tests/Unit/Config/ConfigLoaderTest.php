<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Tests\Support\FilesystemRestriction;
use Greenlight\Tests\Support\FixturePath;

final class ConfigLoaderTest
{
    #[Test]
    public function loadsAValidConfigFileFromADirectory(): void
    {
        $builder = new ConfigLoader()->loadFromDirectory(self::fixtureDir('Valid'));
        $configuration = $builder->build();

        Expect::that($configuration->discovery->paths)->because('loads a valid configuration file from a directory')->toBe(['tests/Unit', 'tests/Acceptance']);
        Expect::that($configuration->workers->count->fixed)->because('loads a valid configuration file from a directory')->toBe(4);
        Expect::that($configuration->execution->stopAfterFailures)->because('loads a valid configuration file from a directory')->toBe(1);
        Expect::that($configuration->order->seed)->because('loads a valid configuration file from a directory')->toBe(4242);
        Expect::that($configuration->discovery->suites)->because('loads a valid configuration file from a directory')->toHaveCount(2);
    }

    #[Test]
    public function missingFileNamesTheDirectoryAndSuggestsAFix(): void
    {
        $directory = self::fixtureDir('Empty');

        Expect::that(static fn(): GreenlightConfig => new ConfigLoader()->loadFromDirectory($directory))
            ->because('a missing default configuration MUST give both available fixes')
            ->toThrow(
                ConfigFileError::class,
                message: \sprintf(
                    'No greenlight.php found in "%s". Create one that returns '
                    . 'GreenlightConfig::create(). Alternatively, use --config=<path> '
                    . 'to select a configuration file.',
                    $directory,
                ),
            );
    }

    #[Test]
    public function missingExplicitFileIsReported(): void
    {
        Expect::that(static function (): void {
            new ConfigLoader()->loadFile(self::fixtureDir('Empty') . '/greenlight.php');
        })->because('missing explicit file is reported')->toThrow(ConfigFileError::class);
    }

    #[Test]
    #[Isolated]
    public function restrictedPathsFailWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 3);
        $restrictedDirectory = \dirname($root);
        $restrictedFile = $restrictedDirectory . '/greenlight.php';
        FilesystemRestriction::toProject($root);

        $loader = new ConfigLoader();
        Expect::that(
            static function () use ($loader, $restrictedDirectory, &$directoryWarning): void {
                ErrorTrap::run(
                    static fn() => $loader->loadFromDirectory($restrictedDirectory),
                    $directoryWarning,
                );
            },
        )->because('a restricted configuration directory causes a configuration error')
            ->toThrow(ConfigFileError::class);
        Expect::that(
            static function () use ($loader, $restrictedFile, &$fileWarning): void {
                ErrorTrap::run(
                    static fn() => $loader->loadFile($restrictedFile),
                    $fileWarning,
                );
            },
        )->because('a restricted configuration file causes a configuration error')
            ->toThrow(ConfigFileError::class);

        Expect::that($directoryWarning)
            ->because('a restricted configuration directory MUST not leak engine diagnostics')
            ->toBeNull();
        Expect::that($fileWarning)
            ->because('a restricted configuration file MUST not leak engine diagnostics')
            ->toBeNull();
    }

    #[Test]
    public function fileReturningTheWrongTypeIsRejectedWithTheActualType(): void
    {
        Expect::that(static fn(): GreenlightConfig => new ConfigLoader()->loadFromDirectory(self::fixtureDir('WrongReturn')))
            ->toThrow(
                static function (ConfigFileError $error): void {
                    Expect::that($error->getMessage())
                        ->toContain('must return a Greenlight\Config\GreenlightConfig instance');
                    Expect::that($error->getMessage())->toContain('returned string');
                },
            );
    }

    #[Test]
    public function throwingConfigFileIsWrappedWithTheOriginalMessage(): void
    {
        Expect::that(static fn(): GreenlightConfig => new ConfigLoader()->loadFromDirectory(self::fixtureDir('Throwing')))
            ->toThrow(
                static function (ConfigFileError $error): void {
                    Expect::that($error->getMessage())->toContain('config exploded');
                    Expect::that($error->getMessage())->toContain('RuntimeException');
                    Expect::that($error->getPrevious())->toBeInstanceOf(\RuntimeException::class);
                    Expect::that($error->getPrevious()->getMessage())->toBe('config exploded');
                },
            );
    }

    private static function fixtureDir(string $name): string
    {
        return FixturePath::get('ConfigFiles/' . $name);
    }
}

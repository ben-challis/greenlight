<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ConfigFileError;
use Greenlight\Config\ConfigLoader;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;

final class ConfigLoaderTest
{
    #[Test]
    public function loadsAValidConfigFileFromADirectory(): void
    {
        $builder = new ConfigLoader()->loadFromDirectory(self::fixtureDir('Valid'));
        $configuration = $builder->build();

        Expect::that($configuration->paths)->toBe(['tests/Unit', 'tests/Acceptance']);
        Expect::that($configuration->workers->fixed)->toBe(4);
        Expect::that($configuration->recycleAfterTests)->toBe(100);
        Expect::that($configuration->recycleAboveMemoryBytes)->toBe(134217728);
        Expect::that($configuration->stopAfterFailures)->toBe(1);
        Expect::that($configuration->randomSeed)->toBe(4242);
        Expect::that($configuration->suites)->toHaveCount(2);
    }

    #[Test]
    public function missingFileNamesTheDirectoryAndSuggestsAFix(): void
    {
        try {
            new ConfigLoader()->loadFromDirectory(self::fixtureDir('Empty'));
        } catch (ConfigFileError $error) {
            Expect::that($error->getMessage())->toContain('greenlight.php');
            Expect::that($error->getMessage())->toContain(self::fixtureDir('Empty'));

            return;
        }

        Fail::because(\sprintf(
            'Expected ConfigLoader::loadFromDirectory() to throw ConfigFileError for missing greenlight.php in "%s".',
            self::fixtureDir('Empty'),
        ));
    }

    #[Test]
    public function missingExplicitFileIsReported(): void
    {
        Expect::that(static function (): void {
            new ConfigLoader()->loadFile(self::fixtureDir('Empty') . '/greenlight.php');
        })->toThrow(ConfigFileError::class);
    }

    #[Test]
    public function fileReturningTheWrongTypeIsRejectedWithTheActualType(): void
    {
        try {
            new ConfigLoader()->loadFromDirectory(self::fixtureDir('WrongReturn'));
        } catch (ConfigFileError $error) {
            Expect::that($error->getMessage())->toContain('must return a Greenlight\Config\GreenlightConfig instance');
            Expect::that($error->getMessage())->toContain('got string');

            return;
        }

        Fail::because(
            'Expected ConfigLoader::loadFromDirectory() to throw ConfigFileError when greenlight.php returns a string.',
        );
    }

    #[Test]
    public function throwingConfigFileIsWrappedWithTheOriginalMessage(): void
    {
        try {
            new ConfigLoader()->loadFromDirectory(self::fixtureDir('Throwing'));
        } catch (ConfigFileError $error) {
            Expect::that($error->getMessage())->toContain('config exploded');
            Expect::that($error->getMessage())->toContain('RuntimeException');
            Expect::that($error->getPrevious())->toBeInstanceOf(\RuntimeException::class);

            return;
        }

        Fail::because(
            'Expected ConfigLoader::loadFromDirectory() to wrap the config RuntimeException in ConfigFileError.',
        );
    }

    private static function fixtureDir(string $name): string
    {
        return \dirname(__DIR__, 2) . '/Fixture/ConfigFiles/' . $name;
    }
}

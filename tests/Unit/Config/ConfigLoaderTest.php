<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ConfigFileNotFound;
use Greenlight\Config\ConfigLoader;
use Greenlight\Config\InvalidConfigFile;
use Greenlight\Tests\Support\Check;

final class ConfigLoaderTest
{
    #[Test]
    public function loadsAValidConfigFileFromADirectory(): void
    {
        $builder = new ConfigLoader()->loadFromDirectory(self::fixtureDir('Valid'));
        $configuration = $builder->build();

        Check::same(['tests/Unit', 'tests/Acceptance'], $configuration->paths, 'paths from the fixture config');
        Check::same(4, $configuration->workers->fixed, 'workers from the fixture config');
        Check::same(100, $configuration->recycleAfterTests, 'recycleAfterTests from the fixture config');
        Check::same(134217728, $configuration->recycleAboveMemoryBytes, 'recycle memory from the fixture config');
        Check::same(1, $configuration->stopAfterFailures, 'failFast from the fixture config');
        Check::same(4242, $configuration->randomSeed, 'seed from the fixture config');
        Check::same(2, \count($configuration->suites), 'suite count from the fixture config');
    }

    #[Test]
    public function missingFileNamesTheDirectoryAndSuggestsAFix(): void
    {
        try {
            new ConfigLoader()->loadFromDirectory(self::fixtureDir('Empty'));
        } catch (ConfigFileNotFound $error) {
            Check::true(
                \str_contains($error->getMessage(), 'greenlight.php'),
                'the error to name the expected file',
            );
            Check::true(
                \str_contains($error->getMessage(), self::fixtureDir('Empty')),
                'the error to name the searched directory',
            );

            return;
        }

        throw new \RuntimeException('Expected ConfigFileNotFound, nothing was thrown.');
    }

    #[Test]
    public function missingExplicitFileIsReported(): void
    {
        Check::throws(
            static function (): void {
                new ConfigLoader()->loadFile(self::fixtureDir('Empty') . '/greenlight.php');
            },
            ConfigFileNotFound::class,
            'loading an explicit path that does not exist',
        );
    }

    #[Test]
    public function fileReturningTheWrongTypeIsRejectedWithTheActualType(): void
    {
        try {
            new ConfigLoader()->loadFromDirectory(self::fixtureDir('WrongReturn'));
        } catch (InvalidConfigFile $error) {
            Check::true(
                \str_contains($error->getMessage(), 'must return a Greenlight\Config\GreenlightConfig instance'),
                'the error to state the required return type',
            );
            Check::true(
                \str_contains($error->getMessage(), 'got string'),
                'the error to state what the file actually returned',
            );

            return;
        }

        throw new \RuntimeException('Expected InvalidConfigFile, nothing was thrown.');
    }

    #[Test]
    public function throwingConfigFileIsWrappedWithTheOriginalMessage(): void
    {
        try {
            new ConfigLoader()->loadFromDirectory(self::fixtureDir('Throwing'));
        } catch (InvalidConfigFile $error) {
            Check::true(
                \str_contains($error->getMessage(), 'config exploded'),
                'the error to carry the original message',
            );
            Check::true(
                \str_contains($error->getMessage(), 'RuntimeException'),
                'the error to carry the original exception class',
            );
            Check::true($error->getPrevious() instanceof \RuntimeException, 'the original exception to be chained');

            return;
        }

        throw new \RuntimeException('Expected InvalidConfigFile, nothing was thrown.');
    }

    private static function fixtureDir(string $name): string
    {
        return \dirname(__DIR__, 2) . '/Fixture/ConfigFiles/' . $name;
    }
}

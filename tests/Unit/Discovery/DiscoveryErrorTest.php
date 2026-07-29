<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Expect\Expect;

final class DiscoveryErrorTest
{
    #[Test]
    public function fileAndTestErrorsIdentifyTheExactSource(): void
    {
        $cause = new \InvalidArgumentException('bad value');
        $attributeError = DiscoveryError::invalidAttribute('App\ExampleTest::checksValue()', $cause);

        $actual = [
            DiscoveryError::directoryNotFound('/project/tests')->getMessage(),
            DiscoveryError::unreadableFile('/project/tests/ExampleTest.php')->getMessage(),
            DiscoveryError::unreadableFile('/project/tests/ExampleTest.php', 'permission denied')->getMessage(),
            DiscoveryError::noClassInFile('/project/tests/ExampleTest.php')->getMessage(),
            DiscoveryError::classNameMismatch('/project/tests/ExampleTest.php', 'OtherTest', 'ExampleTest')->getMessage(),
            DiscoveryError::classNotAutoloadable('/project/tests/ExampleTest.php', 'App\ExampleTest')->getMessage(),
            DiscoveryError::classLoadedFromOtherFile(
                '/project/tests/ExampleTest.php',
                'App\ExampleTest',
                '/project/vendor/ExampleTest.php',
            )->getMessage(),
            DiscoveryError::testMethodNotRunnable('App\ExampleTest', 'checksValue', 'it is static')->getMessage(),
            $attributeError->getMessage(),
        ];

        Expect::that($actual)->toBe([
            'Discovery directory "/project/tests" is missing or is not a directory.',
            'Greenlight cannot read test file "/project/tests/ExampleTest.php".',
            'Greenlight cannot read test file "/project/tests/ExampleTest.php": permission denied.',
            'Test file "/project/tests/ExampleTest.php" does not declare a class, interface, trait, or enum.',
            'Test file "/project/tests/ExampleTest.php" declares "OtherTest". Its file name requires "ExampleTest". Rename the class or file so the names match.',
            'The autoloader cannot load class "App\ExampleTest" from "/project/tests/ExampleTest.php". Check that the namespace matches the PSR-4 mapping for this path.',
            'The autoloader loaded class "App\ExampleTest" from "/project/vendor/ExampleTest.php". It expected the class in "/project/tests/ExampleTest.php". Only one file can declare a class.',
            'Greenlight cannot run test method App\ExampleTest::checksValue() because it is static.',
            'Attribute on App\ExampleTest::checksValue() is invalid: bad value',
        ]);
        Expect::that($attributeError->getPrevious())->toBe($cause);
    }

    #[Test]
    public function unreadableFilesPreserveAZeroStringReason(): void
    {
        Expect::that(DiscoveryError::unreadableFile('/project/tests/ExampleTest.php', '0')->getMessage())
            ->because('an unreadable-file diagnostic MUST preserve a zero-string reason')
            ->toBe('Greenlight cannot read test file "/project/tests/ExampleTest.php": 0.');
    }

    #[Test]
    public function dataProviderErrorsGiveExactGuidance(): void
    {
        $cause = new \RuntimeException('provider failed');
        $providerError = DiscoveryError::providerThrew('App\ExampleTest', 'rows', $cause);

        $actual = [
            DiscoveryError::providerClassMissing('App\ExampleTest', 'checksValue', 'App\Rows')->getMessage(),
            DiscoveryError::providerMissing('App\ExampleTest', 'checksValue', 'App\Rows', 'values')->getMessage(),
            DiscoveryError::providerNotPublicStatic('App\ExampleTest', 'checksValue', 'App\Rows', 'values')->getMessage(),
            DiscoveryError::providerNotIterable('App\Rows', 'values', 'string')->getMessage(),
            $providerError->getMessage(),
            DiscoveryError::providerTooSlow('App\Rows', 'values', 0.125)->getMessage(),
            DiscoveryError::providerYieldedNothing('App\Rows', 'values')->getMessage(),
            DiscoveryError::providerKeyInvalid('App\Rows', 'values', 'float')->getMessage(),
            DiscoveryError::duplicateDataSetKey('App\ExampleTest', 'checksValue', 'same')->getMessage(),
        ];

        Expect::that($actual)->toBe([
            'Test method App\ExampleTest::checksValue() references missing data-set provider class "App\Rows".',
            'Test method App\ExampleTest::checksValue() references data-set provider App\Rows::values(), but the provider does not exist.',
            'Test method App\ExampleTest::checksValue() references data-set provider App\Rows::values(). Declare the provider as public and static.',
            'Data-set provider App\Rows::values() returned string. Return an iterable from the provider.',
            'Data-set provider App\ExampleTest::rows() threw RuntimeException: provider failed',
            'Data-set provider App\Rows::values() exceeded the 0.125-second discovery time budget. Providers run during plan creation. Keep them pure and fast.',
            'Data-set provider App\Rows::values() produced no data sets. Produce at least one data set.',
            'Data-set provider App\Rows::values() produced a key of type float. Use string or integer keys.',
            'Data sets for App\ExampleTest::checksValue() contain key "same" more than once. Use each key only once for the test method.',
        ]);
        Expect::that($providerError->getPrevious())->toBe($cause);
    }
}

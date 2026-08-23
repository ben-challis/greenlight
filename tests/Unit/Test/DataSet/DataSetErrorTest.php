<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test\DataSet;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\DataSet\DataSetError;

final class DataSetErrorTest
{
    #[Test]
    public function dataProviderErrorsGiveExactGuidance(): void
    {
        $cause = new \RuntimeException('provider failed');
        $providerError = DataSetError::providerThrew('App\ExampleTest', 'rows', $cause);

        $actual = [
            DataSetError::providerClassMissing('App\ExampleTest', 'checksValue', 'App\Rows')->getMessage(),
            DataSetError::providerMissing('App\ExampleTest', 'checksValue', 'App\Rows', 'values')->getMessage(),
            DataSetError::providerNotPublicStatic('App\ExampleTest', 'checksValue', 'App\Rows', 'values')->getMessage(),
            DataSetError::providerNotIterable('App\Rows', 'values', 'string')->getMessage(),
            $providerError->getMessage(),
            DataSetError::providerTooSlow('App\Rows', 'values', 0.125)->getMessage(),
            DataSetError::providerYieldedNothing('App\Rows', 'values')->getMessage(),
            DataSetError::providerKeyInvalid('App\Rows', 'values', 'float')->getMessage(),
            DataSetError::duplicateDataSetKey('App\ExampleTest', 'checksValue', 'same')->getMessage(),
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

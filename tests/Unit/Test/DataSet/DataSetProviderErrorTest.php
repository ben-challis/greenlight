<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test\DataSet;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\DataSet\DataSetError;
use Greenlight\Test\DataSet\DataSetExpander;

final readonly class DataSetProviderErrorTest
{
    #[Test]
    public function providerErrorsDuringIterationRetainProviderContext(): void
    {
        $testMethod = __FUNCTION__;

        Expect::that(static fn(): array => new DataSetExpander()->rowsFor(
            new \ReflectionClass(self::class),
            $testMethod,
            'rowsThatThrowDataSetError',
            5.0,
        ))
            ->because('a provider-thrown framework error MUST remain a provider failure')
            ->toThrow(static function (DataSetError $error): void {
                $cause = $error->getPrevious();

                Expect::that($error->getMessage())->toBe(
                    'Data-set provider ' . self::class . '::rowsThatThrowDataSetError() threw '
                    . DataSetError::class . ': Data-set provider MisleadingProvider::rows() produced no data sets. '
                    . 'Produce at least one data set.',
                );
                Expect::that($cause)
                    ->because('the provider wrapper MUST retain the exception from user code')
                    ->toBeInstanceOf(DataSetError::class);
                Expect::that($cause->getMessage())->toBe(
                    'Data-set provider MisleadingProvider::rows() produced no data sets. Produce at least one data set.',
                );
            });
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function rowsThatThrowDataSetError(): iterable
    {
        yield 'first' => [1];

        throw DataSetError::providerYieldedNothing('MisleadingProvider', 'rows');
    }
}

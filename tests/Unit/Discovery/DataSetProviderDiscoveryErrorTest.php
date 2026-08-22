<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DataSetExpander;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Expect\Expect;

final readonly class DataSetProviderDiscoveryErrorTest
{
    #[Test]
    public function providerDiscoveryErrorsDuringIterationRetainProviderContext(): void
    {
        $testMethod = __FUNCTION__;

        Expect::that(static fn(): array => new DataSetExpander()->rowsFor(
            new \ReflectionClass(self::class),
            $testMethod,
            'rowsThatThrowDiscoveryError',
            5.0,
        ))
            ->because('a provider-thrown framework error MUST remain a provider failure')
            ->toThrow(static function (DiscoveryError $error): void {
                $cause = $error->getPrevious();

                Expect::that($error->getMessage())->toBe(
                    'Data-set provider ' . self::class . '::rowsThatThrowDiscoveryError() threw '
                    . DiscoveryError::class . ': Data-set provider MisleadingProvider::rows() produced no data sets. '
                    . 'Produce at least one data set.',
                );
                Expect::that($cause)
                    ->because('the provider wrapper MUST retain the exception from user code')
                    ->toBeInstanceOf(DiscoveryError::class);
                Expect::that($cause->getMessage())->toBe(
                    'Data-set provider MisleadingProvider::rows() produced no data sets. Produce at least one data set.',
                );
            });
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function rowsThatThrowDiscoveryError(): iterable
    {
        yield 'first' => [1];

        throw DiscoveryError::providerYieldedNothing('MisleadingProvider', 'rows');
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test\DataSet;

use Greenlight\Attribute\Test;
use Greenlight\Test\DataSet\DataSetError;
use Greenlight\Test\DataSet\DataSetExpander;
use Greenlight\Tests\Fixture\DiscoveryProviderNormalizedDuplicate\NormalizedDuplicateKeysTest;

use function Greenlight\expect;

final class DataSetNormalizedKeyCollisionTest
{
    #[Test]
    public function integerAndPrintableStringKeysCannotNormalizeToTheSameKey(): void
    {
        $reflection = new \ReflectionClass(NormalizedDuplicateKeysTest::class);

        expect()->calling(static fn(): array => new DataSetExpander()->rowsFor(
            $reflection,
            'needsData',
            'rows',
            5.0,
        ))
            ->because('provider keys MUST remain unique after normalization')
            ->toThrow(
                DataSetError::class,
                message: 'Data sets for '
                    . NormalizedDuplicateKeysTest::class
                    . '::needsData() contain key "#1" more than once. '
                    . 'Use each key only once for the test method.',
            );
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Test\DataSet;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Test\DataSet\DataSetExpander;
use Greenlight\Tests\Fixture\DataRows\InlineRowsTest;

use function Greenlight\expect;

final class DataSetProviderMethodCaseTest
{
    /** @param non-empty-string $provider */
    #[Test]
    #[DataSet('providerNames')]
    public function providerMethodNamesFollowPhpCaseInsensitivity(string $provider): void
    {
        $rows = new DataSetExpander()->rowsFor(
            new \ReflectionClass(InlineRowsTest::class),
            'acceptsWord',
            $provider,
            5.0,
        );

        expect($rows)
            ->because('data-set provider names MUST follow PHP case-insensitive method lookup')
            ->toBe([
                'from attribute' => ['inline'],
                'from provider' => ['provided'],
            ]);
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function providerNames(): iterable
    {
        yield 'upper case' => ['PROVIDEDWORDS'];
        yield 'mixed case' => ['PrOvIdEdWoRdS'];
    }
}

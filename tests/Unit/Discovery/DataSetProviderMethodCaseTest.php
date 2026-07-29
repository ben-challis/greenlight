<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\DataSetExpander;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\DataRows\InlineRowsTest;

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

        Expect::that($rows)
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

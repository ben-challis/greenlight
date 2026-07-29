<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\Filter;
use Greenlight\Expect\Expect;

final readonly class NameFilterCaseTest
{
    #[Test]
    #[DataSet('nameFilters')]
    public function classAndMethodFiltersMatchWithCaseSensitivity(
        Filter $canonical,
        Filter $caseOnlyDifference,
    ): void {
        $class = 'Acme\\InvoiceTest';
        $method = 'calculatesTotal';

        Expect::that($canonical->accepts($class, $method, [], '/tests/InvoiceTest.php'))
            ->because('a class or method filter MUST accept the canonical letter case')
            ->toBeTrue()
            ->and($caseOnlyDifference->accepts($class, $method, [], '/tests/InvoiceTest.php'))
            ->because('class and method filters MUST use the same letter case')
            ->toBeFalse();
    }

    /**
     * @return iterable<string, array{Filter, Filter}>
     */
    public static function nameFilters(): iterable
    {
        yield 'class wildcard' => [
            new Filter(includeClasses: ['Acme\\*Test']),
            new Filter(includeClasses: ['acme\\*test']),
        ];
        yield 'method substring' => [
            new Filter(includeMethods: ['calculates']),
            new Filter(includeMethods: ['CALCULATES']),
        ];
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Test\TestInclusions;
use Greenlight\Test\TestSelection;

use function Greenlight\expect;

final readonly class NameFilterCaseTest
{
    #[Test]
    #[DataSet('nameFilters')]
    public function classAndMethodFiltersMatchWithCaseSensitivity(
        TestSelection $canonical,
        TestSelection $caseOnlyDifference,
    ): void {
        $class = 'Acme\\InvoiceTest';
        $method = 'calculatesTotal';

        expect($canonical->accepts($class, $method, [], '/tests/InvoiceTest.php'))
            ->because('a class or method filter MUST accept the canonical letter case')
            ->toBeTrue();
        expect($caseOnlyDifference->accepts($class, $method, [], '/tests/InvoiceTest.php'))
            ->because('class and method filters MUST use the same letter case')
            ->toBeFalse();
    }

    /**
     * @return iterable<string, array{TestSelection, TestSelection}>
     */
    public static function nameFilters(): iterable
    {
        yield 'class wildcard' => [
            new TestSelection(include: new TestInclusions(classes: ['Acme\\*Test'])),
            new TestSelection(include: new TestInclusions(classes: ['acme\\*test'])),
        ];
        yield 'method substring' => [
            new TestSelection(include: new TestInclusions(methods: ['calculates'])),
            new TestSelection(include: new TestInclusions(methods: ['CALCULATES'])),
        ];
    }
}

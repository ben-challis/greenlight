<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestInclusions;
use Greenlight\Core\Test\TestSelection;
use Greenlight\Expect\Expect;

final class FilterIncludeCompositionTest
{
    /**
     * @param list<string> $groups
     */
    #[Test]
    #[DataSet('candidates')]
    public function everyConfiguredIncludeDimensionMustMatch(
        string $class,
        string $method,
        array $groups,
        string $path,
        bool $accepted,
    ): void {
        $filter = new TestSelection(include: new TestInclusions(
            groups: ['slow'],
            classes: ['App\\Invoice*'],
            methods: ['calculates*'],
            paths: ['/repo/tests/Unit/'],
        ));

        Expect::that($filter->accepts($class, $method, $groups, $path))
            ->because('a candidate MUST satisfy every configured include dimension')
            ->toBe($accepted);
    }

    /**
     * @return iterable<string, array{string, string, list<string>, string, bool}>
     */
    public static function candidates(): iterable
    {
        yield 'all dimensions match' => [
            'App\\InvoiceTest',
            'calculatesTotal',
            ['slow'],
            '/repo/tests/Unit/InvoiceTest.php',
            true,
        ];
        yield 'group does not match' => [
            'App\\InvoiceTest',
            'calculatesTotal',
            ['fast'],
            '/repo/tests/Unit/InvoiceTest.php',
            false,
        ];
        yield 'class does not match' => [
            'App\\OrderTest',
            'calculatesTotal',
            ['slow'],
            '/repo/tests/Unit/OrderTest.php',
            false,
        ];
        yield 'method does not match' => [
            'App\\InvoiceTest',
            'refundsTotal',
            ['slow'],
            '/repo/tests/Unit/InvoiceTest.php',
            false,
        ];
        yield 'path does not match' => [
            'App\\InvoiceTest',
            'calculatesTotal',
            ['slow'],
            '/repo/tests/Acceptance/InvoiceTest.php',
            false,
        ];
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Wildcard;
use Greenlight\Expect\Expect;

final class WildcardTest
{
    #[Test]
    #[DataSet('matchingCases')]
    public function matchesTheDocumentedContract(
        string $subject,
        string $pattern,
        bool $caseInsensitive,
        bool $expected,
    ): void {
        Expect::that(Wildcard::matches($subject, $pattern, $caseInsensitive))
            ->because('wildcard matching MUST follow the documented substring and shell-pattern contract')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{string, string, bool, bool}>
     */
    public static function matchingCases(): iterable
    {
        yield 'plain pattern matches a substring' => [
            'Acme\InvoiceTotalsTest',
            'Invoice',
            false,
            true,
        ];

        yield 'plain pattern rejects a missing substring' => [
            'Acme\InvoiceTotalsTest',
            'Order',
            false,
            false,
        ];

        yield 'plain pattern is case sensitive by default' => [
            'Acme\InvoiceTotalsTest',
            'invoice',
            false,
            false,
        ];

        yield 'plain pattern can ignore case' => [
            'Acme\InvoiceTotalsTest',
            'invoice',
            true,
            true,
        ];

        yield 'wildcard pattern must match the whole subject' => [
            'prefix-value-suffix',
            'value*',
            false,
            false,
        ];

        yield 'surrounding stars match a substring' => [
            'prefix-value-suffix',
            '*value*',
            false,
            true,
        ];

        yield 'star matches zero characters' => [
            'Test',
            'T*est',
            false,
            true,
        ];

        yield 'star matches many characters' => [
            'Test',
            'T*st',
            false,
            true,
        ];

        yield 'question mark matches exactly one character' => [
            'Test',
            'T?st',
            false,
            true,
        ];

        yield 'question mark matches one UTF-8 character' => [
            'Tést',
            'T?st',
            false,
            true,
        ];

        yield 'question mark does not match zero characters' => [
            'Tst',
            'T?st',
            false,
            false,
        ];

        yield 'question mark does not match many characters' => [
            'Toast',
            'T?st',
            false,
            false,
        ];

        yield 'regex metacharacters stay literal' => [
            'Case.[1]+Suffix',
            'Case.[1]+*',
            false,
            true,
        ];

        yield 'literal regex metacharacters do not match regex syntax' => [
            'CaseX1Suffix',
            'Case.[1]+*',
            false,
            false,
        ];

        yield 'wildcard pattern is case sensitive by default' => [
            'Acme\BravoTest::alpha',
            '*BRAVOTEST::ALPHA',
            false,
            false,
        ];

        yield 'wildcard pattern can ignore case' => [
            'Acme\BravoTest::alpha',
            '*BRAVOTEST::ALPHA',
            true,
            true,
        ];
    }
}

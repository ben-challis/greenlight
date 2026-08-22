<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Test\TestInclusions;
use Greenlight\Test\TestSelection;

final class ExactIdCaseTest
{
    /**
     * @param list<non-empty-string> $patterns
     * @param list<non-empty-string> $exactIds
     */
    #[Test]
    #[DataSet('caseRules')]
    public function exactAndPatternIdsApplyTheirDistinctCaseRules(
        array $patterns,
        array $exactIds,
        string $renderedId,
        bool $accepted,
    ): void {
        $filter = new TestSelection(include: new TestInclusions(idPatterns: $patterns, exactIds: $exactIds));

        Expect::that($filter->acceptsId($renderedId))
            ->because('exact IDs MUST match verbatim, while ID patterns MUST ignore letter case')
            ->toBe($accepted);
    }

    /**
     * @return iterable<string, array{
     *     list<non-empty-string>,
     *     list<non-empty-string>,
     *     non-empty-string,
     *     bool
     * }>
     */
    public static function caseRules(): iterable
    {
        $canonical = 'Acme\\InvoiceTest::calculatesTotal';

        yield 'canonical exact ID' => [[], [$canonical], $canonical, true];
        yield 'case-only exact ID difference' => [[], [$canonical], 'acme\\InvoiceTest::calculatesTotal', false];
        yield 'case-only pattern difference' => [['acme\\invoicetest::CALCULATESTOTAL'], [], $canonical, true];
    }
}

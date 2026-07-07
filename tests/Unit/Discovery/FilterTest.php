<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\Filter;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Tests\Support\Check;

final class FilterTest
{
    /**
     * @return non-empty-string
     */
    private function basicDir(): string
    {
        return \dirname(__DIR__, 2) . '/Fixture/DiscoveryBasic';
    }

    /**
     * @return list<string>
     */
    private function ids(ExecutionPlan $plan): array
    {
        $ids = [];

        foreach ($plan->entries as $entry) {
            $ids[] = $entry->id->class . '::' . $entry->id->method;
        }

        return $ids;
    }

    #[Test]
    public function emptyFilterAcceptsEverything(): void
    {
        Check::true(
            Filter::all()->accepts('App\FooTest', 'bar', [], '/src/FooTest.php'),
            'the empty filter to accept anything',
        );
    }

    #[Test]
    public function groupIncludeRequiresAMatchingGroup(): void
    {
        $filter = new Filter(includeGroups: ['slow', 'io']);

        Check::true($filter->accepts('C', 'm', ['io'], '/f'), 'a test in an included group to pass');
        Check::true(!$filter->accepts('C', 'm', ['fast'], '/f'), 'a test in no included group to be rejected');
        Check::true(!$filter->accepts('C', 'm', [], '/f'), 'an ungrouped test to be rejected');
    }

    #[Test]
    public function groupExcludeWinsOverInclude(): void
    {
        $filter = new Filter(includeGroups: ['slow'], excludeGroups: ['flaky']);

        Check::true(!$filter->accepts('C', 'm', ['slow', 'flaky'], '/f'), 'exclusion to win');
        Check::true($filter->accepts('C', 'm', ['slow'], '/f'), 'inclusion to hold without exclusion');
    }

    #[Test]
    public function classFiltersMatchBySubstringOrWildcard(): void
    {
        $substring = new Filter(includeClasses: ['Invoice']);

        Check::true($substring->accepts('App\InvoiceTotalsTest', 'm', [], '/f'), 'substring to match');
        Check::true(!$substring->accepts('App\OrderTest', 'm', [], '/f'), 'non-matching substring to reject');

        $wildcard = new Filter(includeClasses: ['App\*TotalsTest']);

        Check::true($wildcard->accepts('App\InvoiceTotalsTest', 'm', [], '/f'), 'wildcard to match the whole name');
        Check::true(!$wildcard->accepts('App\InvoiceTotalsTestCase', 'm', [], '/f'), 'wildcard to be anchored');

        $question = new Filter(includeClasses: ['App\V?Test']);

        Check::true($question->accepts('App\V1Test', 'm', [], '/f'), 'question mark to match one character');
        Check::true(!$question->accepts('App\V12Test', 'm', [], '/f'), 'question mark to match exactly one character');
    }

    #[Test]
    public function methodFiltersMatchBySubstringOrWildcardAndExclusionWins(): void
    {
        $filter = new Filter(includeMethods: ['handles*'], excludeMethods: ['Slowly']);

        Check::true($filter->accepts('C', 'handlesRefunds', [], '/f'), 'included method to pass');
        Check::true(!$filter->accepts('C', 'ignoresRefunds', [], '/f'), 'non-included method to be rejected');
        Check::true(!$filter->accepts('C', 'handlesRefundsSlowly', [], '/f'), 'excluded method to be rejected');
    }

    #[Test]
    public function pathFiltersMatchByPrefix(): void
    {
        $filter = new Filter(includePaths: ['/repo/tests/Unit'], excludePaths: ['/repo/tests/Unit/Legacy']);

        Check::true($filter->accepts('C', 'm', [], '/repo/tests/Unit/FooTest.php'), 'path under the prefix to pass');
        Check::true(!$filter->accepts('C', 'm', [], '/repo/tests/Acceptance/FooTest.php'), 'path outside the prefix to be rejected');
        Check::true(!$filter->accepts('C', 'm', [], '/repo/tests/Unit/Legacy/FooTest.php'), 'excluded path to be rejected');
    }

    #[Test]
    public function discovererAppliesGroupFilters(): void
    {
        $plan = new TestDiscoverer()->discover([$this->basicDir()], new Filter(includeGroups: ['slow']));

        Check::same([
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two',
            'Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls',
        ], $this->ids($plan), 'plan filtered to the slow group');

        $plan = new TestDiscoverer()->discover([$this->basicDir()], new Filter(excludeGroups: ['slow']));

        Check::same([
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::zulu',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::alpha',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::mike',
            'Greenlight\Tests\Fixture\DiscoveryBasic\DeltaTest::flies',
        ], $this->ids($plan), 'plan excluding the slow group');
    }

    #[Test]
    public function discovererAppliesClassAndMethodFilters(): void
    {
        $plan = new TestDiscoverer()->discover([$this->basicDir()], new Filter(includeClasses: ['BravoTest']));

        Check::same(3, $plan->count(), 'class-filtered plan size');

        $plan = new TestDiscoverer()->discover([$this->basicDir()], new Filter(includeMethods: ['alpha']));

        Check::same(
            ['Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::alpha'],
            $this->ids($plan),
            'method-filtered plan',
        );
    }

    #[Test]
    public function idPatternsMatchBySubstringCaseInsensitively(): void
    {
        $filter = new Filter(includeIds: ['bravotest::ALPHA']);

        Check::true($filter->acceptsId('Greenlight\\Tests\\Fixture\\DiscoveryBasic\\BravoTest::alpha'), 'substring id match');
        Check::true(!($filter->acceptsId('Greenlight\\Tests\\Fixture\\DiscoveryBasic\\BravoTest::beta')), 'non-matching id');
    }

    #[Test]
    public function idWildcardsMatchTheWholeIdIncludingDataSetLabels(): void
    {
        $filter = new Filter(includeIds: ['*BravoTest::alpha*']);

        Check::true($filter->acceptsId('Acme\\BravoTest::alpha'), 'wildcard without label');
        Check::true($filter->acceptsId('Acme\\BravoTest::alpha[edge case]'), 'wildcard with label');
        Check::true(!($filter->acceptsId('Acme\\BravoTest::beta')), 'wildcard rejects other method');

        $labelled = new Filter(includeIds: ['*[edge case]']);

        Check::true($labelled->acceptsId('Acme\\BravoTest::alpha[edge case]'), 'label-anchored wildcard');
        Check::true(!($labelled->acceptsId('Acme\\BravoTest::alpha[other]')), 'label-anchored wildcard rejects');
    }

    #[Test]
    public function exactIdsMatchVerbatimAndUnionWithPatterns(): void
    {
        $filter = new Filter(includeExactIds: ['Acme\\AlphaTest::one']);

        Check::true($filter->acceptsId('Acme\\AlphaTest::one'), 'exact id match');
        Check::true(!($filter->acceptsId('Acme\\AlphaTest::oneMore')), 'exact id refuses supersets');

        $union = new Filter(includeIds: ['::two'], includeExactIds: ['Acme\\AlphaTest::one']);

        Check::true($union->acceptsId('Acme\\AlphaTest::one'), 'union accepts exact side');
        Check::true($union->acceptsId('Acme\\AlphaTest::two'), 'union accepts pattern side');
        Check::true(!($union->acceptsId('Acme\\AlphaTest::three')), 'union rejects neither');
    }

    #[Test]
    public function discovererAppliesIdFilters(): void
    {
        $plan = new TestDiscoverer()->discover([$this->basicDir()], new Filter(includeIds: ['bravotest::alpha']));

        Check::same(['Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::alpha'], $this->ids($plan), 'id-filtered plan');
    }

    #[Test]
    public function discovererAppliesPathPrefixFilters(): void
    {
        $real = \realpath($this->basicDir());
        Check::true(\is_string($real), 'fixture directory to resolve');
        \assert(\is_string($real));

        $plan = new TestDiscoverer()->discover([$this->basicDir()], new Filter(includePaths: [$real . '/Alpha']));

        Check::same([
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one',
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two',
        ], $this->ids($plan), 'path-filtered plan');
    }
}

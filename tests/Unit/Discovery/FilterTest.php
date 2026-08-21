<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestExclusions;
use Greenlight\Core\Test\TestInclusions;
use Greenlight\Core\Test\TestSelection;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\FixturePath;

final class FilterTest
{
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
        Expect::that(new TestSelection()->accepts('App\FooTest', 'bar', [], '/src/FooTest.php'))->because('empty filter accepts everything')->toBeTrue();
    }

    #[Test]
    public function groupIncludeRequiresAMatchingGroup(): void
    {
        $filter = $this->selection(includeGroups: ['slow', 'io']);

        Expect::that($filter->accepts('C', 'm', ['io'], '/f'))->because('group include requires a matching group')->toBeTrue();
        Expect::that($filter->accepts('C', 'm', ['fast'], '/f'))->because('group include requires a matching group')->toBeFalse();
        Expect::that($filter->accepts('C', 'm', [], '/f'))->because('group include requires a matching group')->toBeFalse();
    }

    #[Test]
    public function groupExcludeWinsOverInclude(): void
    {
        $filter = $this->selection(includeGroups: ['slow'], excludeGroups: ['flaky']);

        Expect::that($filter->accepts('C', 'm', ['slow', 'flaky'], '/f'))->because('group exclude wins over include')->toBeFalse();
        Expect::that($filter->accepts('C', 'm', ['slow'], '/f'))->because('group exclude wins over include')->toBeTrue();
    }

    #[Test]
    public function classFiltersMatchBySubstringOrWildcardAndExclusionWins(): void
    {
        $substring = $this->selection(includeClasses: ['Invoice']);

        Expect::that($substring->accepts('App\InvoiceTotalsTest', 'm', [], '/f'))->because('class filters match by substring or wildcard')->toBeTrue();
        Expect::that($substring->accepts('App\OrderTest', 'm', [], '/f'))->because('class filters match by substring or wildcard')->toBeFalse();

        $wildcard = $this->selection(includeClasses: ['App\*TotalsTest']);

        Expect::that($wildcard->accepts('App\InvoiceTotalsTest', 'm', [], '/f'))->because('class filters match by substring or wildcard')->toBeTrue();
        Expect::that($wildcard->accepts('App\InvoiceTotalsTestCase', 'm', [], '/f'))->because('class filters match by substring or wildcard')->toBeFalse();

        $question = $this->selection(includeClasses: ['App\V?Test']);

        Expect::that($question->accepts('App\V1Test', 'm', [], '/f'))->because('class filters match by substring or wildcard')->toBeTrue();
        Expect::that($question->accepts('App\V12Test', 'm', [], '/f'))->because('class filters match by substring or wildcard')->toBeFalse();
        $excluded = $this->selection(includeClasses: ['App\\'], excludeClasses: ['Legacy']);

        Expect::that($excluded->accepts('App\\LegacyTest', 'm', [], '/f'))
            ->because('class exclusion MUST take priority over inclusion')
            ->toBeFalse();
    }

    #[Test]
    public function methodFiltersMatchBySubstringOrWildcardAndExclusionWins(): void
    {
        $filter = $this->selection(includeMethods: ['handles*'], excludeMethods: ['Slowly']);

        Expect::that($filter->accepts('C', 'handlesRefunds', [], '/f'))->because('method filters match by substring or wildcard and exclusion wins')->toBeTrue();
        Expect::that($filter->accepts('C', 'ignoresRefunds', [], '/f'))->because('method filters match by substring or wildcard and exclusion wins')->toBeFalse();
        Expect::that($filter->accepts('C', 'handlesRefundsSlowly', [], '/f'))->because('method filters match by substring or wildcard and exclusion wins')->toBeFalse();
    }

    #[Test]
    public function pathFiltersMatchByPrefix(): void
    {
        $filter = $this->selection(includePaths: ['/repo/tests/Unit'], excludePaths: ['/repo/tests/Unit/Legacy']);

        Expect::that($filter->accepts('C', 'm', [], '/repo/tests/Unit/FooTest.php'))->because('path filters match by prefix')->toBeTrue();
        Expect::that($filter->accepts('C', 'm', [], '/repo/tests/Acceptance/FooTest.php'))->because('path filters match by prefix')->toBeFalse();
        Expect::that($filter->accepts('C', 'm', [], '/repo/tests/Unit/Legacy/FooTest.php'))->because('path filters match by prefix')->toBeFalse();
    }

    #[Test]
    public function discovererAppliesGroupFilters(): void
    {
        $plan = new TestDiscoverer()->discover([FixturePath::get('DiscoveryBasic')], $this->selection(includeGroups: ['slow']));

        Expect::that($this->ids($plan))->because('discoverer applies group filters')->toBe([
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two',
            'Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls',
        ]);

        $plan = new TestDiscoverer()->discover([FixturePath::get('DiscoveryBasic')], $this->selection(excludeGroups: ['slow']));

        Expect::that($this->ids($plan))->because('discoverer applies group filters')->toBe([
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::zulu',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::alpha',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::mike',
            'Greenlight\Tests\Fixture\DiscoveryBasic\DeltaTest::flies',
        ]);
    }

    #[Test]
    public function discovererAppliesClassAndMethodFilters(): void
    {
        $plan = new TestDiscoverer()->discover([FixturePath::get('DiscoveryBasic')], $this->selection(includeClasses: ['BravoTest']));

        Expect::that($plan->count())->because('discoverer applies class and method filters')->toBe(3);

        $plan = new TestDiscoverer()->discover([FixturePath::get('DiscoveryBasic')], $this->selection(includeMethods: ['alpha']));

        Expect::that($this->ids($plan))->because('discoverer applies class and method filters')->toBe(['Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::alpha']);
    }

    #[Test]
    public function idPatternsMatchBySubstringCaseInsensitively(): void
    {
        $filter = $this->selection(includeIds: ['bravotest::ALPHA']);

        Expect::that($filter->acceptsId('Greenlight\\Tests\\Fixture\\DiscoveryBasic\\BravoTest::alpha'))->because('ID patterns match by substring case insensitively')->toBeTrue();
        Expect::that($filter->acceptsId('Greenlight\\Tests\\Fixture\\DiscoveryBasic\\BravoTest::beta'))->because('ID patterns match by substring case insensitively')->toBeFalse();
    }

    #[Test]
    public function idWildcardsMatchTheWholeIdIncludingDataSetLabels(): void
    {
        $filter = $this->selection(includeIds: ['*BravoTest::alpha*']);

        Expect::that($filter->acceptsId('Acme\\BravoTest::alpha'))->because('ID wildcards match the whole ID including data set labels')->toBeTrue();
        Expect::that($filter->acceptsId('Acme\\BravoTest::alpha[edge case]'))->because('ID wildcards match the whole ID including data set labels')->toBeTrue();
        Expect::that($filter->acceptsId('Acme\\BravoTest::beta'))->because('ID wildcards match the whole ID including data set labels')->toBeFalse();

        $labeled = $this->selection(includeIds: ['*[edge case]']);

        Expect::that($labeled->acceptsId('Acme\\BravoTest::alpha[edge case]'))->because('ID wildcards match the whole ID including data set labels')->toBeTrue();
        Expect::that($labeled->acceptsId('Acme\\BravoTest::alpha[other]'))->because('ID wildcards match the whole ID including data set labels')->toBeFalse();
    }

    #[Test]
    public function exactIdsMatchVerbatimAndUnionWithPatterns(): void
    {
        $filter = $this->selection(includeExactIds: ['Acme\\AlphaTest::one']);

        Expect::that($filter->acceptsId('Acme\\AlphaTest::one'))->because('exact IDs match verbatim and union with patterns')->toBeTrue();
        Expect::that($filter->acceptsId('Acme\\AlphaTest::oneMore'))->because('exact IDs match verbatim and union with patterns')->toBeFalse();

        $union = $this->selection(includeIds: ['::two'], includeExactIds: ['Acme\\AlphaTest::one']);

        Expect::that($union->acceptsId('Acme\\AlphaTest::one'))->because('exact IDs match verbatim and union with patterns')->toBeTrue();
        Expect::that($union->acceptsId('Acme\\AlphaTest::two'))->because('exact IDs match verbatim and union with patterns')->toBeTrue();
        Expect::that($union->acceptsId('Acme\\AlphaTest::three'))->because('exact IDs match verbatim and union with patterns')->toBeFalse();
    }

    #[Test]
    public function largeExactIdSelectionsRetainExactMembership(): void
    {
        $ids = [];

        for ($index = 0; $index < 10_000; ++$index) {
            $ids[] = \sprintf('Acme\\GeneratedTest%d::runs', $index);
        }

        $filter = $this->selection(includeExactIds: $ids);

        Expect::that($filter->acceptsId('Acme\\GeneratedTest0::runs'))
            ->because('a large exact-ID selection MUST retain its first member')
            ->toBeTrue();
        Expect::that($filter->acceptsId('Acme\\GeneratedTest9999::runs'))
            ->because('a large exact-ID selection MUST retain its last member')
            ->toBeTrue();
        Expect::that($filter->acceptsId('Acme\\GeneratedTest10000::runs'))
            ->because('a large exact-ID selection MUST reject a nonmember')
            ->toBeFalse();
    }

    #[Test]
    public function discovererAppliesIdFilters(): void
    {
        $plan = new TestDiscoverer()->discover([FixturePath::get('DiscoveryBasic')], $this->selection(includeIds: ['bravotest::alpha']));

        Expect::that($this->ids($plan))->because('discoverer applies ID filters')->toBe(['Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::alpha']);
    }

    #[Test]
    public function discovererAppliesPathPrefixFilters(): void
    {
        $real = \realpath(FixturePath::get('DiscoveryBasic'));
        Expect::that($real)->because('discoverer applies path prefix filters')->toBeString();

        $plan = new TestDiscoverer()->discover([FixturePath::get('DiscoveryBasic')], $this->selection(includePaths: [$real . '/Alpha']));

        Expect::that($this->ids($plan))->because('discoverer applies path prefix filters')->toBe([
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one',
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two',
        ]);
    }

    /**
     * @param list<non-empty-string> $includeGroups
     * @param list<non-empty-string> $excludeGroups
     * @param list<non-empty-string> $includeClasses
     * @param list<non-empty-string> $excludeClasses
     * @param list<non-empty-string> $includeMethods
     * @param list<non-empty-string> $excludeMethods
     * @param list<non-empty-string> $includePaths
     * @param list<non-empty-string> $excludePaths
     * @param list<non-empty-string> $includeIds
     * @param list<non-empty-string> $includeExactIds
     */
    private function selection(
        array $includeGroups = [],
        array $excludeGroups = [],
        array $includeClasses = [],
        array $excludeClasses = [],
        array $includeMethods = [],
        array $excludeMethods = [],
        array $includePaths = [],
        array $excludePaths = [],
        array $includeIds = [],
        array $includeExactIds = [],
    ): TestSelection {
        return new TestSelection(
            new TestInclusions($includeGroups, $includeClasses, $includeMethods, $includePaths, $includeIds, $includeExactIds),
            new TestExclusions($excludeGroups, $excludeClasses, $excludeMethods, $excludePaths),
        );
    }
}

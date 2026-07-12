<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\Filter;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;

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
        Expect::that(Filter::all()->accepts('App\FooTest', 'bar', [], '/src/FooTest.php'))->toBeTrue();
    }

    #[Test]
    public function groupIncludeRequiresAMatchingGroup(): void
    {
        $filter = new Filter(includeGroups: ['slow', 'io']);

        Expect::that($filter->accepts('C', 'm', ['io'], '/f'))->toBeTrue();
        Expect::that($filter->accepts('C', 'm', ['fast'], '/f'))->toBeFalse();
        Expect::that($filter->accepts('C', 'm', [], '/f'))->toBeFalse();
    }

    #[Test]
    public function groupExcludeWinsOverInclude(): void
    {
        $filter = new Filter(includeGroups: ['slow'], excludeGroups: ['flaky']);

        Expect::that($filter->accepts('C', 'm', ['slow', 'flaky'], '/f'))->toBeFalse();
        Expect::that($filter->accepts('C', 'm', ['slow'], '/f'))->toBeTrue();
    }

    #[Test]
    public function classFiltersMatchBySubstringOrWildcard(): void
    {
        $substring = new Filter(includeClasses: ['Invoice']);

        Expect::that($substring->accepts('App\InvoiceTotalsTest', 'm', [], '/f'))->toBeTrue();
        Expect::that($substring->accepts('App\OrderTest', 'm', [], '/f'))->toBeFalse();

        $wildcard = new Filter(includeClasses: ['App\*TotalsTest']);

        Expect::that($wildcard->accepts('App\InvoiceTotalsTest', 'm', [], '/f'))->toBeTrue();
        Expect::that($wildcard->accepts('App\InvoiceTotalsTestCase', 'm', [], '/f'))->toBeFalse();

        $question = new Filter(includeClasses: ['App\V?Test']);

        Expect::that($question->accepts('App\V1Test', 'm', [], '/f'))->toBeTrue();
        Expect::that($question->accepts('App\V12Test', 'm', [], '/f'))->toBeFalse();
    }

    #[Test]
    public function methodFiltersMatchBySubstringOrWildcardAndExclusionWins(): void
    {
        $filter = new Filter(includeMethods: ['handles*'], excludeMethods: ['Slowly']);

        Expect::that($filter->accepts('C', 'handlesRefunds', [], '/f'))->toBeTrue();
        Expect::that($filter->accepts('C', 'ignoresRefunds', [], '/f'))->toBeFalse();
        Expect::that($filter->accepts('C', 'handlesRefundsSlowly', [], '/f'))->toBeFalse();
    }

    #[Test]
    public function pathFiltersMatchByPrefix(): void
    {
        $filter = new Filter(includePaths: ['/repo/tests/Unit'], excludePaths: ['/repo/tests/Unit/Legacy']);

        Expect::that($filter->accepts('C', 'm', [], '/repo/tests/Unit/FooTest.php'))->toBeTrue();
        Expect::that($filter->accepts('C', 'm', [], '/repo/tests/Acceptance/FooTest.php'))->toBeFalse();
        Expect::that($filter->accepts('C', 'm', [], '/repo/tests/Unit/Legacy/FooTest.php'))->toBeFalse();
    }

    #[Test]
    public function discovererAppliesGroupFilters(): void
    {
        $plan = new TestDiscoverer()->discover([$this->basicDir()], new Filter(includeGroups: ['slow']));

        Expect::that($this->ids($plan))->toBe([
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two',
            'Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls',
        ]);

        $plan = new TestDiscoverer()->discover([$this->basicDir()], new Filter(excludeGroups: ['slow']));

        Expect::that($this->ids($plan))->toBe([
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
        $plan = new TestDiscoverer()->discover([$this->basicDir()], new Filter(includeClasses: ['BravoTest']));

        Expect::that($plan->count())->toBe(3);

        $plan = new TestDiscoverer()->discover([$this->basicDir()], new Filter(includeMethods: ['alpha']));

        Expect::that($this->ids($plan))->toBe(['Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::alpha']);
    }

    #[Test]
    public function idPatternsMatchBySubstringCaseInsensitively(): void
    {
        $filter = new Filter(includeIds: ['bravotest::ALPHA']);

        Expect::that($filter->acceptsId('Greenlight\\Tests\\Fixture\\DiscoveryBasic\\BravoTest::alpha'))->toBeTrue();
        Expect::that($filter->acceptsId('Greenlight\\Tests\\Fixture\\DiscoveryBasic\\BravoTest::beta'))->toBeFalse();
    }

    #[Test]
    public function idWildcardsMatchTheWholeIdIncludingDataSetLabels(): void
    {
        $filter = new Filter(includeIds: ['*BravoTest::alpha*']);

        Expect::that($filter->acceptsId('Acme\\BravoTest::alpha'))->toBeTrue();
        Expect::that($filter->acceptsId('Acme\\BravoTest::alpha[edge case]'))->toBeTrue();
        Expect::that($filter->acceptsId('Acme\\BravoTest::beta'))->toBeFalse();

        $labelled = new Filter(includeIds: ['*[edge case]']);

        Expect::that($labelled->acceptsId('Acme\\BravoTest::alpha[edge case]'))->toBeTrue();
        Expect::that($labelled->acceptsId('Acme\\BravoTest::alpha[other]'))->toBeFalse();
    }

    #[Test]
    public function exactIdsMatchVerbatimAndUnionWithPatterns(): void
    {
        $filter = new Filter(includeExactIds: ['Acme\\AlphaTest::one']);

        Expect::that($filter->acceptsId('Acme\\AlphaTest::one'))->toBeTrue();
        Expect::that($filter->acceptsId('Acme\\AlphaTest::oneMore'))->toBeFalse();

        $union = new Filter(includeIds: ['::two'], includeExactIds: ['Acme\\AlphaTest::one']);

        Expect::that($union->acceptsId('Acme\\AlphaTest::one'))->toBeTrue();
        Expect::that($union->acceptsId('Acme\\AlphaTest::two'))->toBeTrue();
        Expect::that($union->acceptsId('Acme\\AlphaTest::three'))->toBeFalse();
    }

    #[Test]
    public function discovererAppliesIdFilters(): void
    {
        $plan = new TestDiscoverer()->discover([$this->basicDir()], new Filter(includeIds: ['bravotest::alpha']));

        Expect::that($this->ids($plan))->toBe(['Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::alpha']);
    }

    #[Test]
    public function discovererAppliesPathPrefixFilters(): void
    {
        $real = \realpath($this->basicDir());
        Expect::that(\is_string($real))->toBeTrue();
        \assert(\is_string($real));

        $plan = new TestDiscoverer()->discover([$this->basicDir()], new Filter(includePaths: [$real . '/Alpha']));

        Expect::that($this->ids($plan))->toBe([
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one',
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two',
        ]);
    }
}

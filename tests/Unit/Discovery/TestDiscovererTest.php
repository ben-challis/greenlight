<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\JsonWire;

final class TestDiscovererTest
{
    /**
     * @return non-empty-string
     */
    private function fixtureDir(string $name): string
    {
        return \dirname(__DIR__, 2) . '/Fixture/' . $name;
    }

    /**
     * @return list<string>
     */
    private function ids(ExecutionPlan $plan): array
    {
        $ids = [];

        foreach ($plan->entries as $entry) {
            $ids[] = (string) $entry->id;
        }

        return $ids;
    }

    #[Test]
    public function rejectsANonpositiveProviderTimeBudgetWithExactGuidance(): void
    {
        Expect::that(
            static fn(): TestDiscoverer => new TestDiscoverer(0.0),
        )->toThrow(
            \InvalidArgumentException::class,
            message: 'Set the provider time budget to a value greater than zero seconds.',
        );
    }

    #[Test]
    public function discoversBasicSuiteInFileOrderWithoutSeed(): void
    {
        $plan = new TestDiscoverer()->discover([$this->fixtureDir('DiscoveryBasic')]);

        Expect::that($this->ids($plan))->because('discovers basic suite in file order without seed')->toBe([
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one',
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::zulu',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::alpha',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::mike',
            'Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls',
            'Greenlight\Tests\Fixture\DiscoveryBasic\DeltaTest::flies',
        ]);

        Expect::that($plan->seed)->because('discovers basic suite in file order without seed')->toBe(null);
        Expect::that($plan->count())->because('discovers basic suite in file order without seed')->toBe(7);
    }

    #[Test]
    public function abstractClassesAndClassesWithoutTestsAreSkipped(): void
    {
        $plan = new TestDiscoverer()->discover([$this->fixtureDir('DiscoveryBasic')]);

        foreach ($plan->classes() as $class) {
            Expect::that($class)
                ->not()->toContain('AbstractSharedTest')
                ->not()->toContain('NoTestMethodsTest');
        }
    }

    #[Test]
    public function sameSeedProducesByteIdenticalPlans(): void
    {
        $discoverer = new TestDiscoverer();
        $first = $discoverer->discover([$this->fixtureDir('DiscoveryBasic')], null, 1234);
        $second = $discoverer->discover([$this->fixtureDir('DiscoveryBasic')], null, 1234);

        Expect::that(\json_encode($second->toWire(), \JSON_THROW_ON_ERROR))->because('same seed produces byte identical plans')
            ->toBe(\json_encode($first->toWire(), \JSON_THROW_ON_ERROR));
        Expect::that($first->seed)->because('same seed produces byte identical plans')->toBe(1234);
    }

    #[Test]
    public function differentSeedsProduceDifferentClassOrder(): void
    {
        $discoverer = new TestDiscoverer();
        $orders = [];

        foreach ([1, 2, 3, 4, 5] as $seed) {
            $orders[] = \implode(',', $discoverer->discover([$this->fixtureDir('DiscoveryBasic')], null, $seed)->classes());
        }

        Expect::that(\count(\array_unique($orders)))->because('different seeds produce different class order')->toBeGreaterThan(1);
    }

    #[Test]
    public function seededPlanKeepsMethodDeclarationOrderWithinClass(): void
    {
        $plan = new TestDiscoverer()->discover([$this->fixtureDir('DiscoveryBasic')], null, 42);
        $bravoMethods = [];

        foreach ($plan->entries as $entry) {
            if (\str_ends_with($entry->id->class, 'BravoTest')) {
                $bravoMethods[] = $entry->id->method;
            }
        }

        Expect::that($bravoMethods)->because('seeded plan keeps method declaration order within class')->toBe(['zulu', 'alpha', 'mike']);
    }

    #[Test]
    public function seededPlanSurvivesTheWire(): void
    {
        $plan = new TestDiscoverer()->discover([$this->fixtureDir('DiscoveryBasic')], null, 99);
        $restored = ExecutionPlan::fromWire(JsonWire::roundTrip($plan->toWire()));

        Expect::that(\json_encode($restored->toWire(), \JSON_THROW_ON_ERROR))->because('seeded plan survives the wire')
            ->toBe(\json_encode($plan->toWire(), \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function unknownDirectoryFailsLoudly(): void
    {
        $directory = $this->fixtureDir('DoesNotExist');

        Expect::that(
            static fn(): ExecutionPlan => new TestDiscoverer()->discover([$directory]),
        )->because('unknown directory fails loudly')->toThrow(
            DiscoveryError::class,
            message: \sprintf('Discovery directory "%s" is missing or is not a directory.', $directory),
        );
    }

    #[Test]
    public function overlappingDirectoriesDoNotDuplicateEntries(): void
    {
        $dir = $this->fixtureDir('DiscoveryBasic');
        $plan = new TestDiscoverer()->discover([$dir, $dir]);

        Expect::that($plan->count())->because('overlapping directories do not duplicate entries')->toBe(7);
    }
}

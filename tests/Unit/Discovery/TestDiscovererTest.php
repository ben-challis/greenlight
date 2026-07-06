<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Tests\Support\Check;

final class TestDiscovererTest
{
    /**
     * @return non-empty-string
     */
    private static function fixtureDir(string $name): string
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
    public function discoversBasicSuiteInFileOrderWithoutSeed(): void
    {
        $plan = new TestDiscoverer()->discover([self::fixtureDir('DiscoveryBasic')]);

        Check::same([
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one',
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::zulu',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::alpha',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::mike',
            'Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls',
            'Greenlight\Tests\Fixture\DiscoveryBasic\DeltaTest::flies',
        ], $this->ids($plan), 'default plan order');

        Check::same(null, $plan->seed, 'seed of unseeded plan');
        Check::same(7, $plan->count(), 'plan size');
    }

    #[Test]
    public function abstractClassesAndClassesWithoutTestsAreSkipped(): void
    {
        $plan = new TestDiscoverer()->discover([self::fixtureDir('DiscoveryBasic')]);

        foreach ($plan->classes() as $class) {
            Check::true(
                !\str_contains($class, 'AbstractSharedTest') && !\str_contains($class, 'NoTestMethodsTest'),
                \sprintf('class "%s" should not be planned', $class),
            );
        }
    }

    #[Test]
    public function sameSeedProducesByteIdenticalPlans(): void
    {
        $discoverer = new TestDiscoverer();
        $first = $discoverer->discover([self::fixtureDir('DiscoveryBasic')], null, 1234);
        $second = $discoverer->discover([self::fixtureDir('DiscoveryBasic')], null, 1234);

        Check::same(
            \json_encode($first->toWire(), \JSON_THROW_ON_ERROR),
            \json_encode($second->toWire(), \JSON_THROW_ON_ERROR),
            'plans for the same seed',
        );
        Check::same(1234, $first->seed, 'seed recorded on the plan');
    }

    #[Test]
    public function differentSeedsProduceDifferentClassOrder(): void
    {
        $discoverer = new TestDiscoverer();
        $orders = [];

        foreach ([1, 2, 3, 4, 5] as $seed) {
            $orders[] = \implode(',', $discoverer->discover([self::fixtureDir('DiscoveryBasic')], null, $seed)->classes());
        }

        Check::true(
            \count(\array_unique($orders)) > 1,
            'at least two of five seeds to produce different class orders',
        );
    }

    #[Test]
    public function seededPlanKeepsMethodDeclarationOrderWithinClass(): void
    {
        $plan = new TestDiscoverer()->discover([self::fixtureDir('DiscoveryBasic')], null, 42);
        $bravoMethods = [];

        foreach ($plan->entries as $entry) {
            if (\str_ends_with($entry->id->class, 'BravoTest')) {
                $bravoMethods[] = $entry->id->method;
            }
        }

        Check::same(['zulu', 'alpha', 'mike'], $bravoMethods, 'method order within a class');
    }

    #[Test]
    public function seededPlanSurvivesTheWire(): void
    {
        $plan = new TestDiscoverer()->discover([self::fixtureDir('DiscoveryBasic')], null, 99);
        $restored = ExecutionPlan::fromWire(Check::jsonRoundTrip($plan->toWire()));

        Check::same(
            \json_encode($plan->toWire(), \JSON_THROW_ON_ERROR),
            \json_encode($restored->toWire(), \JSON_THROW_ON_ERROR),
            'plan after a wire round trip',
        );
    }

    #[Test]
    public function unknownDirectoryFailsLoudly(): void
    {
        Check::throws(
            static fn(): ExecutionPlan => new TestDiscoverer()->discover([self::fixtureDir('DoesNotExist')]),
            DiscoveryError::class,
            'discovery of a missing directory',
        );
    }

    #[Test]
    public function overlappingDirectoriesDoNotDuplicateEntries(): void
    {
        $dir = self::fixtureDir('DiscoveryBasic');
        $plan = new TestDiscoverer()->discover([$dir, $dir]);

        Check::same(7, $plan->count(), 'plan size for duplicated input directories');
    }
}

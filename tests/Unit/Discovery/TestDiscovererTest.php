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

        Expect::that($this->ids($plan))->toBe([
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one',
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::zulu',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::alpha',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::mike',
            'Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls',
            'Greenlight\Tests\Fixture\DiscoveryBasic\DeltaTest::flies',
        ]);

        Expect::that($plan->seed)->toBe(null);
        Expect::that($plan->count())->toBe(7);
    }

    #[Test]
    public function abstractClassesAndClassesWithoutTestsAreSkipped(): void
    {
        $plan = new TestDiscoverer()->discover([self::fixtureDir('DiscoveryBasic')]);

        foreach ($plan->classes() as $class) {
            Expect::that(
                !\str_contains($class, 'AbstractSharedTest') && !\str_contains($class, 'NoTestMethodsTest'),
            )->toBeTrue();
        }
    }

    #[Test]
    public function sameSeedProducesByteIdenticalPlans(): void
    {
        $discoverer = new TestDiscoverer();
        $first = $discoverer->discover([self::fixtureDir('DiscoveryBasic')], null, 1234);
        $second = $discoverer->discover([self::fixtureDir('DiscoveryBasic')], null, 1234);

        Expect::that(\json_encode($second->toWire(), \JSON_THROW_ON_ERROR))
            ->toBe(\json_encode($first->toWire(), \JSON_THROW_ON_ERROR));
        Expect::that($first->seed)->toBe(1234);
    }

    #[Test]
    public function differentSeedsProduceDifferentClassOrder(): void
    {
        $discoverer = new TestDiscoverer();
        $orders = [];

        foreach ([1, 2, 3, 4, 5] as $seed) {
            $orders[] = \implode(',', $discoverer->discover([self::fixtureDir('DiscoveryBasic')], null, $seed)->classes());
        }

        Expect::that(\count(\array_unique($orders)))->toBeGreaterThan(1);
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

        Expect::that($bravoMethods)->toBe(['zulu', 'alpha', 'mike']);
    }

    #[Test]
    public function seededPlanSurvivesTheWire(): void
    {
        $plan = new TestDiscoverer()->discover([self::fixtureDir('DiscoveryBasic')], null, 99);
        $restored = ExecutionPlan::fromWire(JsonWire::roundTrip($plan->toWire()));

        Expect::that(\json_encode($restored->toWire(), \JSON_THROW_ON_ERROR))
            ->toBe(\json_encode($plan->toWire(), \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function unknownDirectoryFailsLoudly(): void
    {
        Expect::that(
            static fn(): ExecutionPlan => new TestDiscoverer()->discover([self::fixtureDir('DoesNotExist')]),
        )->toThrow(DiscoveryError::class);
    }

    #[Test]
    public function overlappingDirectoriesDoNotDuplicateEntries(): void
    {
        $dir = self::fixtureDir('DiscoveryBasic');
        $plan = new TestDiscoverer()->discover([$dir, $dir]);

        Expect::that($plan->count())->toBe(7);
    }
}

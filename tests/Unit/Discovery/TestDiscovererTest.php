<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Core\ErrorTrap;
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
    #[DataSet('invalidProviderTimeBudgets')]
    public function rejectsAnInvalidProviderTimeBudgetWithExactGuidance(float $budgetSeconds): void
    {
        Expect::that(
            static fn(): TestDiscoverer => new TestDiscoverer($budgetSeconds),
        )->toThrow(
            \InvalidArgumentException::class,
            message: 'Provider time budget seconds must be finite and greater than zero.',
        );
    }

    /**
     * @return iterable<string, array{float}>
     */
    public static function invalidProviderTimeBudgets(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'positive infinity' => [\INF];
        yield 'negative infinity' => [-\INF];
        yield 'not a number' => [\NAN];
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
        )->because('an unknown directory causes an error')->toThrow(
            DiscoveryError::class,
            message: \sprintf('Discovery directory "%s" is missing or is not a directory.', $directory),
        );
    }

    #[Test]
    #[Isolated]
    public function inaccessibleDirectoryFailsWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 3);
        $directory = \dirname($root);
        $previousOpenBasedir = \ini_set('open_basedir', $root . \PATH_SEPARATOR . \sys_get_temp_dir());

        Expect::that($previousOpenBasedir)
            ->because('the isolated fixture MUST restrict access to the discovery directory')
            ->not()
            ->toBeFalse();

        Expect::that(
            static function () use ($directory, &$warning): void {
                ErrorTrap::run(
                    static fn(): array => new TestDiscoverer()->testFiles([$directory]),
                    $warning,
                );
            },
        )->because('an inaccessible discovery directory causes a domain error')->toThrow(
            DiscoveryError::class,
            message: \sprintf('Discovery directory "%s" is missing or is not a directory.', $directory),
        );

        Expect::that($warning)
            ->because('inaccessible discovery paths MUST not leak engine diagnostics')
            ->toBeNull();
    }

    #[Test]
    public function overlappingDirectoriesDoNotDuplicateEntries(): void
    {
        $dir = $this->fixtureDir('DiscoveryBasic');
        $plan = new TestDiscoverer()->discover([$dir, $dir]);

        Expect::that($plan->count())->because('overlapping directories do not duplicate entries')->toBe(7);
    }
}

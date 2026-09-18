<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryError;
use Greenlight\Discovery\Plan\ExecutionPlan;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Tests\Support\FilesystemRestriction;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\JsonWire;

use function Greenlight\expect;

final class TestDiscovererTest
{
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
        expect()->calling(
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
        $plan = new TestDiscoverer()->discover([FixturePath::get('DiscoveryBasic')]);

        expect($this->ids($plan))->because('discovers basic suite in file order without seed')->toBe([
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::one',
            'Greenlight\Tests\Fixture\DiscoveryBasic\AlphaTest::two',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::zulu',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::alpha',
            'Greenlight\Tests\Fixture\DiscoveryBasic\BravoTest::mike',
            'Greenlight\Tests\Fixture\DiscoveryBasic\CharlieTest::crawls',
            'Greenlight\Tests\Fixture\DiscoveryBasic\DeltaTest::flies',
        ]);

        expect($plan->seed)->because('discovers basic suite in file order without seed')->toBe(null);
        expect($plan->count())->because('discovers basic suite in file order without seed')->toBe(7);
    }

    #[Test]
    public function abstractClassesAndClassesWithoutTestsAreSkipped(): void
    {
        $plan = new TestDiscoverer()->discover([FixturePath::get('DiscoveryBasic')]);

        foreach ($plan->classes() as $class) {
            expect($class)
                ->not()->toContain('AbstractSharedTest')
                ->not()->toContain('NoTestMethodsTest');
        }
    }

    #[Test]
    public function sameSeedProducesByteIdenticalPlans(): void
    {
        $discoverer = new TestDiscoverer();
        $first = $discoverer->discover([FixturePath::get('DiscoveryBasic')], null, 1234);
        $second = $discoverer->discover([FixturePath::get('DiscoveryBasic')], null, 1234);

        expect(\json_encode($second->toWire(), \JSON_THROW_ON_ERROR))->because('same seed produces byte identical plans')
            ->toBe(\json_encode($first->toWire(), \JSON_THROW_ON_ERROR));
        expect($first->seed)->because('same seed produces byte identical plans')->toBe(1234);
    }

    #[Test]
    public function differentSeedsProduceDifferentClassOrder(): void
    {
        $discoverer = new TestDiscoverer();
        $orders = [];

        foreach ([1, 2, 3, 4, 5] as $seed) {
            $orders[] = \implode(',', $discoverer->discover([FixturePath::get('DiscoveryBasic')], null, $seed)->classes());
        }

        expect(\count(\array_unique($orders)))->because('different seeds produce different class order')->toBeGreaterThan(1);
    }

    #[Test]
    public function seededPlanKeepsMethodDeclarationOrderWithinClass(): void
    {
        $plan = new TestDiscoverer()->discover([FixturePath::get('DiscoveryBasic')], null, 42);
        $bravoMethods = [];

        foreach ($plan->entries as $entry) {
            if (\str_ends_with($entry->id->class, 'BravoTest')) {
                $bravoMethods[] = $entry->id->method;
            }
        }

        expect($bravoMethods)->because('seeded plan keeps method declaration order within class')->toBe(['zulu', 'alpha', 'mike']);
    }

    #[Test]
    public function seededPlanSurvivesTheWire(): void
    {
        $plan = new TestDiscoverer()->discover([FixturePath::get('DiscoveryBasic')], null, 99);
        $restored = ExecutionPlan::fromWire(JsonWire::roundTrip($plan->toWire()));

        expect(\json_encode($restored->toWire(), \JSON_THROW_ON_ERROR))->because('seeded plan survives the wire')
            ->toBe(\json_encode($plan->toWire(), \JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function unknownDirectoryFailsLoudly(): void
    {
        $directory = FixturePath::get('DoesNotExist');

        expect()->calling(
            static fn(): ExecutionPlan => new TestDiscoverer()->discover([$directory]),
        )->because('an unknown directory causes an error')->toThrow(
            DiscoveryError::class,
            message: \sprintf('Discovery directory "%s" is missing or is not a directory.', $directory),
        );
    }

    #[Test]
    public function aNativeDirectoryThrowableBecomesADiscoveryError(): void
    {
        expect()->calling(
            static fn(): array => new TestDiscoverer()->testFiles(["invalid\0tests"]),
        )
            ->because('a native directory throwable MUST not escape the discovery seam')
            ->toThrow(
                static function (DiscoveryError $error): void {
                    expect($error->getPrevious())
                        ->because('the discovery error MUST preserve the native directory error')
                        ->toBeInstanceOf(\ValueError::class);
                },
            );
    }

    #[Test]
    #[Isolated]
    public function inaccessibleDirectoryFailsWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 3);
        $directory = \dirname($root);
        FilesystemRestriction::toProject($root);

        expect()->calling(
            static function () use ($directory, &$warning): void {
                ErrorTrap::run(
                    static fn() => new TestDiscoverer()->testFiles([$directory]),
                    $warning,
                );
            },
        )->because('an inaccessible discovery directory causes a domain error')->toThrow(
            DiscoveryError::class,
            message: \sprintf('Discovery directory "%s" is missing or is not a directory.', $directory),
        );

        expect($warning)
            ->because('inaccessible discovery paths MUST not leak engine diagnostics')
            ->toBeNull();
    }

    #[Test]
    public function overlappingDirectoriesDoNotDuplicateEntries(): void
    {
        $dir = FixturePath::get('DiscoveryBasic');
        $plan = new TestDiscoverer()->discover([$dir, $dir]);

        expect($plan->count())->because('overlapping directories do not duplicate entries')->toBe(7);
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Configuration;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Configuration\CliOverrides;
use Greenlight\Cli\Configuration\ConfigurationResolver;
use Greenlight\Cli\Configuration\ExecutionOverrides;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\ResolvedConfiguration;
use Greenlight\Expect\Expect;
use Greenlight\Result\ResultPolicy;
use Greenlight\Result\RunPolicy;
use Greenlight\Test\TestExclusions;
use Greenlight\Test\TestInclusions;
use Greenlight\Test\TestSelection;

final readonly class ConfigurationResolverSelectionTest
{
    #[Test]
    public function selectionOverridesAreForwardedWithoutCrossWiring(): void
    {
        $resolved = $this->resolve(new CliOverrides(
            selection: new TestSelection(
                include: new TestInclusions(idPatterns: ['Acme\\*'], exactIds: ['Acme\\SelectedTest::runs']),
                exclude: new TestExclusions(['slow'], ['Acme\\Legacy*'], ['flaky*'], ['tests/Legacy']),
                shard: [2, 3],
            ),
        ));

        Expect::that($resolved->selection->include->idPatterns)
            ->because('the filter override MUST set the filters field')
            ->toBe(['Acme\\*']);
        Expect::that($resolved->selection->include->exactIds)
            ->because('the test ID override MUST set the onlyTests field')
            ->toBe(['Acme\\SelectedTest::runs']);
        Expect::that($resolved->selection->shard)
            ->because('the shard override MUST set the shard field')
            ->toBe([2, 3]);
        Expect::that($resolved->selection->exclude->groups)
            ->because('the group exclusion override MUST set the excludeGroups field')
            ->toBe(['slow']);
        Expect::that($resolved->selection->exclude->classes)
            ->because('the class exclusion override MUST set the excludeClasses field')
            ->toBe(['Acme\\Legacy*']);
        Expect::that($resolved->selection->exclude->methods)
            ->because('the method exclusion override MUST set the excludeMethods field')
            ->toBe(['flaky*']);
        Expect::that($resolved->selection->exclude->paths)
            ->because('the path exclusion override MUST set the excludePaths field')
            ->toBe(['tests/Legacy']);
    }

    /**
     * @param array{bool, bool, bool, bool, bool, bool} $expected
     */
    #[Test]
    #[DataSet('failurePolicyOverrides')]
    public function failurePolicyOverridesAreForwardedIndependently(
        CliOverrides $overrides,
        array $expected,
    ): void {
        $policy = $this->resolve($overrides)->execution->policy;

        Expect::that($policy->failOnDeprecation)
            ->because('the deprecation policy flag MUST map to failOnDeprecation')
            ->toBe($expected[0]);
        Expect::that($policy->failOnNotice)
            ->because('the notice policy flag MUST map to failOnNotice')
            ->toBe($expected[1]);
        Expect::that($policy->failOnRisky)
            ->because('the risky-test policy flag MUST map to failOnRisky')
            ->toBe($expected[2]);
        Expect::that($policy->failOnWarning)
            ->because('the warning policy flag MUST map to failOnWarning')
            ->toBe($expected[3]);
        Expect::that($this->resolve($overrides)->execution->runPolicy->failOnSkipped)
            ->because('the skipped-test policy flag MUST map to failOnSkipped')
            ->toBe($expected[4]);
        Expect::that($this->resolve($overrides)->execution->runPolicy->failOnRetriedPass)
            ->because('the retried-pass policy flag MUST map to failOnRetriedPass')
            ->toBe($expected[5]);
    }

    /**
     * @return iterable<string, array{CliOverrides, array{bool, bool, bool, bool, bool, bool}}>
     */
    public static function failurePolicyOverrides(): iterable
    {
        yield 'deprecation' => [
            new CliOverrides(execution: new ExecutionOverrides(policy: new ResultPolicy(failOnDeprecation: true))),
            [true, false, false, false, false, false],
        ];
        yield 'notice' => [
            new CliOverrides(execution: new ExecutionOverrides(policy: new ResultPolicy(failOnNotice: true))),
            [false, true, false, false, false, false],
        ];
        yield 'risky' => [
            new CliOverrides(execution: new ExecutionOverrides(policy: new ResultPolicy(failOnRisky: true))),
            [false, false, true, false, false, false],
        ];
        yield 'warning' => [
            new CliOverrides(execution: new ExecutionOverrides(policy: new ResultPolicy(failOnWarning: true))),
            [false, false, false, true, false, false],
        ];
        yield 'skipped' => [
            new CliOverrides(execution: new ExecutionOverrides(runPolicy: new RunPolicy(failOnSkipped: true))),
            [false, false, false, false, true, false],
        ];
        yield 'retried pass' => [
            new CliOverrides(execution: new ExecutionOverrides(runPolicy: new RunPolicy(failOnRetriedPass: true))),
            [false, false, false, false, false, true],
        ];
    }

    private function resolve(CliOverrides $overrides): ResolvedConfiguration
    {
        return ConfigurationResolver::resolve(
            GreenlightConfig::create()->build(),
            $overrides,
        );
    }
}

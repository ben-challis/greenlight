<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ConfigurationResolver;
use Greenlight\Config\Configuration;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Expect\Expect;

final readonly class ConfigurationResolverSelectionTest
{
    #[Test]
    public function selectionOverridesAreForwardedWithoutCrossWiring(): void
    {
        $resolved = $this->resolve(new CliOverrides(
            filters: ['Acme\\*'],
            testIds: ['Acme\\SelectedTest::runs'],
            shard: [2, 3],
            excludeGroups: ['slow'],
            excludeClasses: ['Acme\\Legacy*'],
            excludeMethods: ['flaky*'],
            excludePaths: ['tests/Legacy'],
        ));

        Expect::that($resolved->filters)
            ->because('the filter override MUST set the filters field')
            ->toBe(['Acme\\*']);
        Expect::that($resolved->onlyTests)
            ->because('the test ID override MUST set the onlyTests field')
            ->toBe(['Acme\\SelectedTest::runs']);
        Expect::that($resolved->shard)
            ->because('the shard override MUST set the shard field')
            ->toBe([2, 3]);
        Expect::that($resolved->excludeGroups)
            ->because('the group exclusion override MUST set the excludeGroups field')
            ->toBe(['slow']);
        Expect::that($resolved->excludeClasses)
            ->because('the class exclusion override MUST set the excludeClasses field')
            ->toBe(['Acme\\Legacy*']);
        Expect::that($resolved->excludeMethods)
            ->because('the method exclusion override MUST set the excludeMethods field')
            ->toBe(['flaky*']);
        Expect::that($resolved->excludePaths)
            ->because('the path exclusion override MUST set the excludePaths field')
            ->toBe(['tests/Legacy']);
    }

    /**
     * @param array{bool, bool, bool} $expected
     */
    #[Test]
    #[DataSet('failurePolicyOverrides')]
    public function failurePolicyOverridesAreForwardedIndependently(
        CliOverrides $overrides,
        array $expected,
    ): void {
        $policy = $this->resolve($overrides)->policy;

        Expect::that([
            $policy->failOnDeprecation,
            $policy->failOnNotice,
            $policy->failOnRisky,
        ])
            ->because('failure policy CLI flags MUST map to their matching policy fields')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{CliOverrides, array{bool, bool, bool}}>
     */
    public static function failurePolicyOverrides(): iterable
    {
        yield 'deprecation' => [
            new CliOverrides(failOnDeprecation: true),
            [true, false, false],
        ];
        yield 'notice' => [
            new CliOverrides(failOnNotice: true),
            [false, true, false],
        ];
        yield 'risky' => [
            new CliOverrides(failOnRisky: true),
            [false, false, true],
        ];
    }

    private function resolve(CliOverrides $overrides): Configuration
    {
        return ConfigurationResolver::resolve(
            GreenlightConfig::create()->build(),
            $overrides,
        );
    }
}

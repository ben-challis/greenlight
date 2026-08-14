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

        Expect::that([
            'filters' => $resolved->filters,
            'onlyTests' => $resolved->onlyTests,
            'shard' => $resolved->shard,
            'excludeGroups' => $resolved->excludeGroups,
            'excludeClasses' => $resolved->excludeClasses,
            'excludeMethods' => $resolved->excludeMethods,
            'excludePaths' => $resolved->excludePaths,
        ])
            ->because('selection CLI options MUST map to their matching configuration fields')
            ->toBe([
                'filters' => ['Acme\\*'],
                'onlyTests' => ['Acme\\SelectedTest::runs'],
                'shard' => [2, 3],
                'excludeGroups' => ['slow'],
                'excludeClasses' => ['Acme\\Legacy*'],
                'excludeMethods' => ['flaky*'],
                'excludePaths' => ['tests/Legacy'],
            ]);
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

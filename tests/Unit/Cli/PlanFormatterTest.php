<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\CliOverrides;
use Greenlight\Cli\ConfigurationResolver;
use Greenlight\Cli\ExecutionOverrides;
use Greenlight\Cli\PlanFormatter;
use Greenlight\Config\CoverageBuilder;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\SuiteBuilder;
use Greenlight\Core\Test\TestInclusions;
use Greenlight\Core\Test\TestSelection;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Plugins\NamedFakePlugin;

final class PlanFormatterTest
{
    #[Test]
    public function formatsTheResolvedPlanWithExactLabels(): void
    {
        $configuration = ConfigurationResolver::resolve(
            GreenlightConfig::create()
                ->coverage(static fn(CoverageBuilder $coverage) => $coverage
                    ->include('src')
                    ->driver('xdebug')
                    ->export('json', 'build/coverage.json'))
                ->build(),
            new CliOverrides(),
        );

        $temporary = \rtrim(\sys_get_temp_dir(), '/');
        $projectKey = \substr(\sha1('/project'), 0, 12);

        Expect::that(PlanFormatter::format($configuration, '/project/greenlight.php', '/project'))->toBe(
            <<<PLAN
                Run plan
                  configuration file: /project/greenlight.php
                  test paths: tests
                  suites: (none)
                  workers: auto
                  resource limits: (default 1 per required resource)
                  stop after: never
                  order: declared
                  groups: (all)
                  plugins: (none)
                  artifacts: build/greenlight-artifacts
                  storage state: {$temporary}/greenlight-state-{$projectKey}.json
                  storage cache: {$temporary}
                  storage generated code: {$temporary}/greenlight-proxies-{$projectKey}
                  storage temporary: {$temporary}
                  coverage include paths: src
                  coverage driver: xdebug
                  coverage exports: json -> build/coverage.json

                PLAN,
        );
    }

    #[Test]
    public function formatsRuntimeSeedSelectionAndPluginClasses(): void
    {
        $configuration = ConfigurationResolver::resolve(
            GreenlightConfig::create()
                ->randomizeOrder()
                ->plugins(static fn(): NamedFakePlugin => new NamedFakePlugin())
                ->build(),
            new CliOverrides(),
        );

        $formatted = PlanFormatter::format($configuration, '/project/greenlight.php', '/project');

        Expect::that($formatted)
            ->because('the plan names the one resolved seed and configured plugins')
            ->toContain('  order: random (seed ');
        Expect::that($formatted)
            ->toContain('  plugins: ' . NamedFakePlugin::class);
    }

    /**
     * @param positive-int $failureLimit
     */
    #[Test]
    #[DataSet('failureLimits')]
    public function formatsConfiguredPlanDetails(int $failureLimit, string $expectedFailureLimit): void
    {
        $configuration = ConfigurationResolver::resolve(
            GreenlightConfig::create()
                ->suite('unit', static fn(SuiteBuilder $suite) => $suite->in('tests/Unit')->tag('fast'))
                ->suite('acceptance', static fn(SuiteBuilder $suite) => $suite->in('tests/Acceptance'))
                ->workers(count: 3)
                ->resourceLimit('database', 2)
                ->resourceLimit('redis')
                ->plugins(static fn(): NamedFakePlugin => new NamedFakePlugin())
                ->randomizeOrder(4242)
                ->build(),
            new CliOverrides(
                execution: new ExecutionOverrides(stopAfterFailures: $failureLimit),
                selection: new TestSelection(include: new TestInclusions(groups: ['smoke', 'unit'])),
            ),
        );

        $temporary = \rtrim(\sys_get_temp_dir(), '/');
        $projectKey = \substr(\sha1('/project'), 0, 12);

        Expect::that(PlanFormatter::format($configuration, '/project/greenlight.php', '/project'))
            ->because('the run plan MUST show each configured execution detail')
            ->toBe(
                <<<PLAN
                    Run plan
                      configuration file: /project/greenlight.php
                      test paths: tests
                      suite unit: tests/Unit [tags: fast]
                      suite acceptance: tests/Acceptance
                      workers: 3
                      resource limits: database=2, redis=1
                      stop after: {$expectedFailureLimit}
                      order: random (seed 4242)
                      groups: smoke, unit
                      plugins: Greenlight\Tests\Fixture\Plugins\NamedFakePlugin
                      artifacts: build/greenlight-artifacts
                      storage state: {$temporary}/greenlight-state-{$projectKey}.json
                      storage cache: {$temporary}
                      storage generated code: {$temporary}/greenlight-proxies-{$projectKey}
                      storage temporary: {$temporary}
                      coverage: (off)

                    PLAN,
            );
    }

    /**
     * @return iterable<string, array{positive-int, non-empty-string}>
     */
    public static function failureLimits(): iterable
    {
        yield 'singular' => [1, '1 failure'];

        yield 'plural' => [3, '3 failures'];
    }
}

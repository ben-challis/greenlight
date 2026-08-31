<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Configuration;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Configuration\CliOverrides;
use Greenlight\Cli\Configuration\ConfigurationResolver;
use Greenlight\Cli\Configuration\ExecutionOverrides;
use Greenlight\Cli\Configuration\PlanFormatter;
use Greenlight\Config\CoverageBuilder;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\SuiteBuilder;
use Greenlight\Config\WatchBuilder;
use Greenlight\Expect\Expect;
use Greenlight\Test\TestInclusions;
use Greenlight\Test\TestSelection;
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
                    ->requireDriver()
                    ->minimumPercentage(95.25)
                    ->maximumUncoveredLines(3)
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
                  watch debounce: 200 ms
                  additional watch paths: (none)
                  watch include patterns: (all additional directory files)
                  watch exclude patterns: (none)
                  watch file limit: 100000
                  coverage include paths: src
                  coverage driver: xdebug
                  coverage driver required: yes
                  minimum coverage: 95.25%
                  maximum uncovered lines: 3
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
                      watch debounce: 200 ms
                      additional watch paths: (none)
                      watch include patterns: (all additional directory files)
                      watch exclude patterns: (none)
                      watch file limit: 100000
                      coverage: (off)

                    PLAN,
            );
    }

    #[Test]
    public function formatsConfiguredWatchInputs(): void
    {
        $configuration = ConfigurationResolver::resolve(
            GreenlightConfig::create()
                ->watch(static fn(WatchBuilder $watch) => $watch
                    ->debounceMilliseconds(350)
                    ->paths('templates', 'config/app.yaml')
                    ->include('**/*.twig', '**/*.yaml')
                    ->exclude('build/**')
                    ->maximumFiles(2_000))
                ->build(),
            new CliOverrides(),
        );

        Expect::that(PlanFormatter::format($configuration, '/project/greenlight.php', '/project'))
            ->toContain('  watch debounce: 350 ms')
            ->toContain('  additional watch paths: templates, config/app.yaml')
            ->toContain('  watch include patterns: **/*.twig, **/*.yaml')
            ->toContain('  watch exclude patterns: build/**')
            ->toContain('  watch file limit: 2000');
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

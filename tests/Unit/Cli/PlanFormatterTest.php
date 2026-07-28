<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\PlanFormatter;
use Greenlight\Config\Configuration;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\CoverageExport;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\SuiteConfiguration;
use Greenlight\Config\WatchConfiguration;
use Greenlight\Config\WorkerCount;
use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Plugins\NamedFakePlugin;

final class PlanFormatterTest
{
    #[Test]
    public function formatsTheResolvedPlanWithExactLabels(): void
    {
        $configuration = new Configuration(
            paths: ['tests'],
            suites: [],
            workers: WorkerCount::auto(),
            recycleAfterTests: null,
            recycleAboveMemoryBytes: 128 * 1024 * 1024,
            coverage: new CoverageConfiguration(
                includePaths: ['src'],
                driver: 'xdebug',
                exports: [new CoverageExport('json', 'build/coverage.json')],
            ),
            watch: new WatchConfiguration(),
            plugins: [],
            policy: new ResultPolicy(),
            stopAfterFailures: null,
            randomizeOrder: false,
            randomSeed: null,
        );

        Expect::that(PlanFormatter::format($configuration, '/project/greenlight.php'))->toBe(
            <<<'PLAN'
                Run plan
                  configuration file: /project/greenlight.php
                  test paths: tests
                  suites: (none)
                  workers: auto
                  resource limits: (default 1 per required resource)
                  recycle: above 128M memory
                  stop after: never
                  order: declared
                  groups: (all)
                  plugins: (none)
                  artifacts: build/greenlight-artifacts
                  coverage include paths: src
                  coverage driver: xdebug
                  coverage exports: json -> build/coverage.json

                PLAN,
        );
    }

    #[Test]
    public function formatsRuntimeSeedSelectionAndPluginClasses(): void
    {
        $configuration = GreenlightConfig::create()
            ->randomizeOrder()
            ->plugins(new NamedFakePlugin())
            ->build();

        $formatted = PlanFormatter::format($configuration, '/project/greenlight.php');

        Expect::that($formatted)
            ->because('the plan names runtime seed selection and configured plugins')
            ->toContain('  order: random (seed chosen at run time)')
            ->and($formatted)
            ->toContain('  plugins: ' . NamedFakePlugin::class);
    }

    /**
     * @param positive-int $failureLimit
     */
    #[Test]
    #[DataSet('failureLimits')]
    public function formatsConfiguredPlanDetails(int $failureLimit, string $expectedFailureLimit): void
    {
        $configuration = new Configuration(
            paths: ['tests'],
            suites: [
                new SuiteConfiguration('unit', ['tests/Unit'], ['fast']),
                new SuiteConfiguration('acceptance', ['tests/Acceptance'], []),
            ],
            workers: WorkerCount::exactly(3),
            recycleAfterTests: 25,
            recycleAboveMemoryBytes: 256 * 1024 * 1024,
            coverage: null,
            watch: new WatchConfiguration(),
            plugins: [new NamedFakePlugin()],
            policy: new ResultPolicy(),
            stopAfterFailures: $failureLimit,
            randomizeOrder: true,
            randomSeed: 4242,
            groups: ['smoke', 'unit'],
            resourceLimits: ['database' => 2, 'redis' => 1],
        );

        Expect::that(PlanFormatter::format($configuration, '/project/greenlight.php'))
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
                      recycle: after 25 tests or above 256M memory
                      stop after: {$expectedFailureLimit}
                      order: random (seed 4242)
                      groups: smoke, unit
                      plugins: Greenlight\Tests\Fixture\Plugins\NamedFakePlugin
                      artifacts: build/greenlight-artifacts
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

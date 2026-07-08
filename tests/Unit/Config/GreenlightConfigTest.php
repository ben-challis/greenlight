<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\CoverageBuilder;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\SuiteBuilder;
use Greenlight\Tests\Support\Check;

final class GreenlightConfigTest
{
    #[Test]
    public function buildsDocumentedDefaults(): void
    {
        $configuration = GreenlightConfig::create()->build();

        Check::same(['tests'], $configuration->paths, 'default paths');
        Check::same([], $configuration->suites, 'default suites');
        Check::true($configuration->workers->isAuto(), 'default worker count to be auto');
        Check::same(null, $configuration->recycleAfterTests, 'default recycleAfterTests of never');
        Check::same(268435456, $configuration->recycleAboveMemoryBytes, 'default recycle memory of 256M');
        Check::same(null, $configuration->coverage, 'default coverage');
        Check::same([], $configuration->plugins, 'default plugins');
        Check::same(null, $configuration->stopAfterFailures, 'default stopAfterFailures');
        Check::same(false, $configuration->randomizeOrder, 'default randomizeOrder');
        Check::same(null, $configuration->randomSeed, 'default seed');
        Check::same([], $configuration->groups, 'default groups');
    }

    #[Test]
    public function buildsAFullyConfiguredRun(): void
    {
        $plugin = new \stdClass();

        $configuration = GreenlightConfig::create()
            ->paths(['tests/Unit', 'tests/Integration'])
            ->suite('unit', static fn(SuiteBuilder $suite) => $suite->in('tests/Unit'))
            ->suite('integration', static fn(SuiteBuilder $suite) => $suite->in('tests/Integration')->tag('io', 'slow'))
            ->workers(count: 8, recycleAfterTests: 250, recycleAboveMemory: '1G')
            ->coverage(static fn(CoverageBuilder $coverage) => $coverage->include('src')->driver('pcov')->export('lcov', 'coverage/lcov.info'))
            ->plugins($plugin)
            ->failFast()
            ->randomizeOrder(seed: 99)
            ->build();

        Check::same(['tests/Unit', 'tests/Integration'], $configuration->paths, 'paths');
        Check::same(2, \count($configuration->suites), 'suite count');
        Check::same('unit', $configuration->suites[0]->name, 'first suite name');
        Check::same(['tests/Integration'], $configuration->suites[1]->paths, 'second suite paths');
        Check::same(['io', 'slow'], $configuration->suites[1]->tags, 'second suite tags');
        Check::same(8, $configuration->workers->fixed, 'worker count');
        Check::same(250, $configuration->recycleAfterTests, 'recycleAfterTests');
        Check::same(1073741824, $configuration->recycleAboveMemoryBytes, 'recycle memory bytes');
        $coverage = $configuration->coverage;

        if (!$coverage instanceof CoverageConfiguration) {
            throw new \RuntimeException('Expected coverage to be configured.');
        }

        Check::same(['src'], $coverage->includePaths, 'coverage include paths');
        Check::same('pcov', $coverage->driver, 'coverage driver');
        Check::same('lcov', $coverage->exports[0]->format, 'coverage export format');
        Check::same('coverage/lcov.info', $coverage->exports[0]->target, 'coverage export target');
        Check::same([$plugin], $configuration->plugins, 'plugins');
        Check::same(1, $configuration->stopAfterFailures, 'failFast maps to stopping after the first failure');
        Check::same(true, $configuration->randomizeOrder, 'randomizeOrder');
        Check::same(99, $configuration->randomSeed, 'seed');
    }

    #[Test]
    public function randomizeOrderWithoutSeedStillEnablesRandomization(): void
    {
        $configuration = GreenlightConfig::create()->randomizeOrder()->build();

        Check::same(true, $configuration->randomizeOrder, 'randomizeOrder');
        Check::same(null, $configuration->randomSeed, 'seed left for the runner to choose');
    }

    #[Test]
    public function rejectsInvalidInput(): void
    {
        $invalid = [
            'empty paths' => static function (): void {
                GreenlightConfig::create()->paths([]);
            },
            'empty path string' => static function (): void {
                GreenlightConfig::create()->paths(['']);
            },
            'empty suite name' => static function (): void {
                GreenlightConfig::create()->suite('', static fn(SuiteBuilder $suite) => $suite->in('tests'));
            },
            'duplicate suite' => static function (): void {
                GreenlightConfig::create()
                    ->suite('unit', static fn(SuiteBuilder $suite) => $suite->in('tests'))
                    ->suite('unit', static fn(SuiteBuilder $suite) => $suite->in('tests'));
            },
            'suite without paths' => static function (): void {
                GreenlightConfig::create()
                    ->suite('unit', static function (SuiteBuilder $suite): void {})
                    ->build();
            },
            'zero workers' => static function (): void {
                GreenlightConfig::create()->workers(count: 0);
            },
            'bad worker string' => static function (): void {
                // Reflection bypasses the static 'auto'|int type to hit the runtime guard.
                new \ReflectionMethod(GreenlightConfig::class, 'workers')
                    ->invoke(GreenlightConfig::create(), 'many');
            },
            'zero recycleAfterTests' => static function (): void {
                GreenlightConfig::create()->workers(recycleAfterTests: 0);
            },
            'bad memory string surfaces at build' => static function (): void {
                GreenlightConfig::create()->workers(recycleAboveMemory: 'lots')->build();
            },
        ];

        foreach ($invalid as $what => $callable) {
            Check::throws($callable, InvalidConfiguration::class, $what);
        }
    }

    #[Test]
    public function badMemoryStringIsAcceptedUntilBuild(): void
    {
        $builder = GreenlightConfig::create()->workers(recycleAboveMemory: 'lots');

        Check::throws(
            static function () use ($builder): void {
                $builder->build();
            },
            InvalidConfiguration::class,
            'building with an unparseable memory string',
        );
    }
}

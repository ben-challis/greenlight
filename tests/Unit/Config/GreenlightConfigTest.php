<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactBuilder;
use Greenlight\Config\CoverageBuilder;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Config\StorageBuilder;
use Greenlight\Config\SuiteBuilder;
use Greenlight\Config\WatchBuilder;
use Greenlight\Event\Event;
use Greenlight\Plugin\RunLifecycleSubscriber;

use function Greenlight\expect;

final class GreenlightConfigTest
{
    #[Test]
    public function buildsDocumentedDefaults(): void
    {
        $configuration = GreenlightConfig::create()->build();

        expect($configuration->discovery->paths)->because('builds documented defaults')->toBe(['tests']);
        expect($configuration->discovery->suites)->because('builds documented defaults')->toBe([]);
        expect($configuration->workers->count->isAuto())->because('builds documented defaults')->toBeTrue();
        expect($configuration->coverage)->because('builds documented defaults')->toBe(null);
        expect($configuration->execution->plugins)->because('builds documented defaults')->toBe([]);
        expect($configuration->execution->stopAfterFailures)->because('builds documented defaults')->toBe(null);
        expect($configuration->order->randomized)->because('builds documented defaults')->toBe(false);
        expect($configuration->order->seed)->because('builds documented defaults')->toBe(null);
        expect($configuration->execution->artifacts->directory)->because('builds documented defaults')->toBe('build/greenlight-artifacts');
        expect($configuration->execution->artifacts->maxAttachmentsPerTest)->because('builds documented defaults')->toBe(32);
        expect($configuration->workers->resourceLimits)->because('builds documented defaults')->toBe([]);
    }

    #[Test]
    public function retainsAZeroTestPath(): void
    {
        $configuration = GreenlightConfig::create()
            ->paths(['0'])
            ->build();

        expect($configuration->discovery->paths)
            ->because('configuration MUST retain each non-empty test path')
            ->toBe(['0']);
    }

    #[Test]
    public function buildsAFullyConfiguredRun(): void
    {
        $plugin = static fn(): ConfigRunSubscriber => new ConfigRunSubscriber();

        $configuration = GreenlightConfig::create()
            ->paths(['tests/Unit', 'tests/Integration'])
            ->suite('unit', static fn(SuiteBuilder $suite) => $suite->in('tests/Unit'))
            ->suite('integration', static fn(SuiteBuilder $suite) => $suite->in('tests/Integration')->tag('io', 'slow'))
            ->workers(count: 8)
            ->resourceLimit('postgres', 3)
            ->resourceLimit('payments-sandbox')
            ->coverage(static fn(CoverageBuilder $coverage) => $coverage->include('src')->driver('pcov')->export('lcov', 'coverage/lcov.info')->perTest('coverage/tests.jsonl'))
            ->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts
                ->directory('build/evidence')
                ->maxAttachmentsPerTest(10)
                ->maxAttachmentSize('5M')
                ->maxTestSize('20M')
                ->maxRunAttachments(100)
                ->maxRunSize('100M'))
            ->plugins($plugin)
            ->failFast()
            ->randomizeOrder(seed: 99)
            ->build();

        expect($configuration->discovery->paths)->because('builds a fully configured run')->toBe(['tests/Unit', 'tests/Integration']);
        expect($configuration->discovery->suites)->because('builds a fully configured run')->toHaveCount(2);
        expect($configuration->discovery->suites[0]->name)->because('builds a fully configured run')->toBe('unit');
        expect($configuration->discovery->suites[1]->paths)->because('builds a fully configured run')->toBe(['tests/Integration']);
        expect($configuration->discovery->suites[1]->tags)->because('builds a fully configured run')->toBe(['io', 'slow']);
        expect($configuration->workers->count->fixed)->because('builds a fully configured run')->toBe(8);
        $coverage = $configuration->coverage;

        expect($coverage)
            ->because('GreenlightConfig::build() MUST return a CoverageConfiguration')
            ->toBeInstanceOf(CoverageConfiguration::class);

        expect($coverage->includePaths)->because('builds a fully configured run')->toBe(['src']);
        expect($coverage->driver)->because('builds a fully configured run')->toBe('pcov');
        expect($coverage->exports[0]->format)->because('builds a fully configured run')->toBe('lcov');
        expect($coverage->exports[0]->target)->because('builds a fully configured run')->toBe('coverage/lcov.info');
        expect($coverage->perTestTarget)->because('builds a fully configured run')->toBe('coverage/tests.jsonl');
        expect($configuration->execution->plugins)->because('builds a fully configured run')->toHaveCount(1);
        expect($configuration->execution->plugins[0]->pluginClass)->because('builds a fully configured run')->toBe(ConfigRunSubscriber::class);
        expect($configuration->execution->stopAfterFailures)->because('builds a fully configured run')->toBe(1);
        expect($configuration->order->randomized)->because('builds a fully configured run')->toBe(true);
        expect($configuration->order->seed)->because('builds a fully configured run')->toBe(99);
        expect($configuration->execution->artifacts->directory)->because('builds a fully configured run')->toBe('build/evidence');
        expect($configuration->execution->artifacts->maxAttachmentBytes)->because('builds a fully configured run')->toBe(5 * 1024 * 1024);
        expect($configuration->execution->artifacts->maxRunBytes)->because('builds a fully configured run')->toBe(100 * 1024 * 1024);
        expect($configuration->workers->resourceLimits)->because('builds a fully configured run')->toBe(['postgres' => 3, 'payments-sandbox' => 1]);
    }

    #[Test]
    public function preservesAZeroStringSuiteNameThroughBuild(): void
    {
        $configuration = GreenlightConfig::create()
            ->suite('0', static fn(SuiteBuilder $suite) => $suite->in('tests')->tag('fast'))
            ->build();

        expect($configuration->discovery->suites[0]->name)
            ->because('a zero-string suite name is not empty')
            ->toBe('0');
        expect($configuration->discovery->suites[0]->paths)
            ->because('the suite MUST retain its configured paths')
            ->toBe(['tests']);
        expect($configuration->discovery->suites[0]->tags)
            ->because('the suite MUST retain its configured tags')
            ->toBe(['fast']);
    }

    #[Test]
    public function randomizeOrderWithoutSeedStillEnablesRandomization(): void
    {
        $configuration = GreenlightConfig::create()->randomizeOrder()->build();

        expect($configuration->order->randomized)->because('randomize order without seed still enables randomization')->toBe(true);
        expect($configuration->order->seed)->because('randomize order without seed still enables randomization')->toBe(null);
    }

    #[Test]
    public function zeroIsAValidConfiguredSeed(): void
    {
        $configuration = GreenlightConfig::create()->randomizeOrder(seed: 0)->build();

        expect($configuration->order->randomized)
            ->because('zero MUST enable random order')
            ->toBeTrue();
        expect($configuration->order->seed)
            ->because('zero MUST remain the configured seed')
            ->toBe(0);
    }

    #[Test]
    public function aNegativeSeedDoesNotPartiallyChangeTheBuilder(): void
    {
        $builder = GreenlightConfig::create()->randomizeOrder(seed: 7);

        expect()->calling(static fn(): GreenlightConfig => $builder->randomizeOrder(seed: -1)) // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            ->because('a negative seed MUST be rejected')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'Random order seed must be a nonnegative integer. Actual value: -1.',
            );

        expect($builder->build()->order->seed)
            ->because('a rejected seed MUST retain the prior seed')
            ->toBe(7);
    }

    #[Test]
    public function rejectedWorkerConfigurationsDoNotPartiallyChangeTheBuilder(): void
    {
        $builder = GreenlightConfig::create()->workers(count: 2);

        expect()->calling(static fn(): GreenlightConfig => $builder->workers(count: 0)) // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            ->because('a rejected worker configuration does not partially change the builder')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'Worker count must be at least 1, got 0.',
            );

        $configuration = $builder->build();

        expect($configuration->workers->count->fixed)
            ->because('a rejected worker configuration retains the prior worker count')
            ->toBe(2);
    }

    #[Test]
    public function anEmptyCoverageConfiguratorEnablesDefaultCoverage(): void
    {
        $configuration = GreenlightConfig::create()
            ->coverage(static function (CoverageBuilder $coverage): void {})
            ->build();

        expect($configuration->coverage)->toBeInstanceOf(CoverageConfiguration::class);
        expect($configuration->coverage->includePaths)->toBe([]);
        expect($configuration->coverage->driver)->toBe(null);
        expect($configuration->coverage->exports)->toBe([]);
    }

    #[Test]
    public function rejectedCoverageConfigurationDoesNotEnableCoverage(): void
    {
        $builder = GreenlightConfig::create();

        expect()->calling(static fn(): GreenlightConfig => $builder->coverage(
            static fn(CoverageBuilder $coverage) => $coverage->include(''), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        ))
            ->because('a rejected coverage configuration does not enable coverage')
            ->toThrow(InvalidConfiguration::class);

        expect($builder->build()->coverage)
            ->because('coverage stays off when its configurator fails')
            ->toBe(null);
    }

    #[Test]
    public function rejectedNestedConfigurationsDoNotPartiallyChangeTheBuilder(): void
    {
        $builder = GreenlightConfig::create()
            ->coverage(static fn(CoverageBuilder $coverage) => $coverage
                ->include('src')
                ->driver('pcov'))
            ->watch(static fn(WatchBuilder $watch) => $watch->debounceMilliseconds(500))
            ->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts->directory('build/original'))
            ->storage(static fn(StorageBuilder $storage) => $storage->cacheDirectory('build/original-cache'));

        expect()->calling(static fn(): GreenlightConfig => $builder->coverage(
            static fn(CoverageBuilder $coverage) => $coverage
                ->driver('xdebug')
                ->include(''), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        ))
            ->because('a rejected coverage configuration does not partially change the builder')
            ->toThrow(InvalidConfiguration::class);

        expect()->calling(static fn(): GreenlightConfig => $builder->watch(
            static fn(WatchBuilder $watch) => $watch
                ->debounceMilliseconds(750)
                ->debounceMilliseconds(0), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        ))
            ->because('a rejected watch configuration does not partially change the builder')
            ->toThrow(InvalidConfiguration::class);

        expect()->calling(static fn(): GreenlightConfig => $builder->artifacts(
            static fn(ArtifactBuilder $artifacts) => $artifacts
                ->directory('build/changed')
                ->maxRunAttachments(0), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        ))
            ->because('a rejected artifact configuration does not partially change the builder')
            ->toThrow(InvalidConfiguration::class);

        expect()->calling(static fn(): GreenlightConfig => $builder->storage(
            static fn(StorageBuilder $storage) => $storage
                ->cacheDirectory('build/changed-cache')
                ->temporaryDirectory(''), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        ))
            ->because('a rejected storage configuration does not partially change the builder')
            ->toThrow(InvalidConfiguration::class);

        $configuration = $builder->build();

        expect($configuration->coverage?->includePaths)
            ->because('a rejected coverage configuration retains the prior include paths')
            ->toBe(['src']);
        expect($configuration->coverage?->driver)
            ->because('a rejected coverage configuration retains the prior driver')
            ->toBe('pcov');
        expect($configuration->watch->debounceMilliseconds)
            ->because('a rejected watch configuration retains the prior debounce')
            ->toBe(500);
        expect($configuration->execution->artifacts->directory)
            ->because('a rejected artifact configuration retains the prior directory')
            ->toBe('build/original');
        expect($configuration->storage->cacheDirectory)
            ->because('a rejected storage configuration retains the prior cache directory')
            ->toBe('build/original-cache');
    }

    #[Test]
    public function rejectedDeprecationPatternsDoNotPartiallyChangeTheBuilder(): void
    {
        $builder = GreenlightConfig::create()->ignoreDeprecationsMatching('existing');

        expect()->calling(static fn(): GreenlightConfig => $builder->ignoreDeprecationsMatching('added', '')) // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            ->because('a rejected deprecation pattern does not partially change the builder')
            ->toThrow(InvalidConfiguration::class);

        expect($builder->build()->execution->policy->ignoreDeprecations)
            ->because('a rejected deprecation pattern retains the prior patterns')
            ->toBe(['existing']);
    }

    /**
     * @param \Closure(): void $callable
     */
    #[Test]
    #[DataSet('invalidInputs')]
    public function rejectsInvalidInput(\Closure $callable): void
    {
        expect()->calling($callable)->toThrow(InvalidConfiguration::class);
    }

    /** @param array<mixed> $paths */
    #[Test]
    #[DataSet('invalidPaths')]
    public function invalidPathsGiveExactGuidance(array $paths, string $message): void
    {
        expect()->calling(static function () use ($paths): void {
            new \ReflectionMethod(GreenlightConfig::class, 'paths')
                ->invoke(GreenlightConfig::create(), $paths);
        })
            ->because('each invalid path shape MUST identify the required fix')
            ->toThrow(InvalidConfiguration::class, message: $message);
    }

    /**
     * @return iterable<string, array{array<mixed>, non-empty-string}>
     */
    public static function invalidPaths(): iterable
    {
        yield 'no directories' => [
            [],
            'paths() needs at least one directory.',
        ];

        yield 'directories are not a list' => [
            ['unit' => 'tests/Unit'],
            'Test paths must be a list.',
        ];

        yield 'directory is not a string' => [
            [42],
            'Test paths must contain only strings.',
        ];

        yield 'empty directory' => [
            [''],
            'Test paths cannot be empty strings.',
        ];

        yield 'directory contains a null byte' => [
            ["tests/Unit\0hidden"],
            'Test paths cannot contain a null byte.',
        ];
    }

    /**
     * @param \Closure(): void $configure
     */
    #[Test]
    #[DataSet('invalidSuites')]
    public function invalidSuitesGiveExactGuidance(\Closure $configure, string $message): void
    {
        expect()->calling($configure)
            ->because('each invalid suite definition MUST identify the required fix')
            ->toThrow(InvalidConfiguration::class, message: $message);
    }

    /**
     * @return iterable<string, array{\Closure(): void, non-empty-string}>
     */
    public static function invalidSuites(): iterable
    {
        yield 'empty name' => [
            static function (): void {
                GreenlightConfig::create()->suite(
                    '', // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
                    static fn(SuiteBuilder $suite) => $suite->in('tests'),
                );
            },
            'Suite names cannot be empty.',
        ];

        yield 'duplicate name' => [
            static function (): void {
                GreenlightConfig::create()
                    ->suite('unit', static fn(SuiteBuilder $suite) => $suite->in('tests'))
                    ->suite('unit', static fn(SuiteBuilder $suite) => $suite->in('tests'));
            },
            'Suite "unit" is declared twice.',
        ];

        yield 'no paths' => [
            static function (): void {
                GreenlightConfig::create()
                    ->suite('unit', static function (SuiteBuilder $suite): void {});
            },
            'Suite "unit" has no paths. Call in() with at least one directory inside its configurator.',
        ];

        yield 'empty path' => [
            static function (): void {
                GreenlightConfig::create()->suite(
                    'unit',
                    static fn(SuiteBuilder $suite) => $suite->in(''), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
                );
            },
            'Suite "unit" was given an empty path.',
        ];

        yield 'empty tag' => [
            static function (): void {
                GreenlightConfig::create()->suite(
                    'unit',
                    static fn(SuiteBuilder $suite) => $suite->in('tests')->tag(''), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
                );
            },
            'Suite "unit" was given an empty tag.',
        ];
    }

    /**
     * @param \Closure(): void $configure
     */
    #[Test]
    #[DataSet('invalidResourceLimits')]
    public function invalidResourceLimitsGiveExactGuidance(\Closure $configure, string $message): void
    {
        expect()->calling($configure)
            ->because('each invalid resource limit MUST identify the required fix')
            ->toThrow(InvalidConfiguration::class, message: $message);
    }

    /**
     * @return iterable<string, array{\Closure(): void, non-empty-string}>
     */
    public static function invalidResourceLimits(): iterable
    {
        yield 'negative limit' => [
            static function (): void {
                GreenlightConfig::create()->resourceLimit('redis', -2); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            },
            'Resource "redis" must have a limit of at least 1, got -2.',
        ];

        yield 'duplicate declaration' => [
            static function (): void {
                GreenlightConfig::create()
                    ->resourceLimit('redis')
                    ->resourceLimit('redis', 3);
            },
            'Resource limit "redis" is declared twice.',
        ];
    }

    /**
     * @return iterable<string, array{\Closure(): void}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'zero workers' => [static function (): void {
            GreenlightConfig::create()->workers(count: 0); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        }];

        yield 'bad worker string' => [static function (): void {
            // Reflection bypasses the static 'auto'|int type and exercises the
            // runtime guard.
            new \ReflectionMethod(GreenlightConfig::class, 'workers')
                ->invoke(GreenlightConfig::create(), 'many');
        }];

        yield 'empty artifact directory' => [static function (): void {
            GreenlightConfig::create()->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts->directory('')); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        }];

        yield 'invalid resource name' => [static function (): void {
            GreenlightConfig::create()->resourceLimit('Postgres');
        }];

        yield 'zero resource limit' => [static function (): void {
            GreenlightConfig::create()->resourceLimit('postgres', 0); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        }];

        yield 'duplicate resource limit' => [static function (): void {
            GreenlightConfig::create()->resourceLimit('postgres')->resourceLimit('postgres', 2);
        }];
    }

    /**
     * @param \Closure(): void $configure
     */
    #[Test]
    #[DataSet('invalidArtifactCounts')]
    public function invalidArtifactCountsGiveExactGuidance(\Closure $configure, string $message): void
    {
        expect()->calling($configure)
            ->because('artifact count limits must be positive')
            ->toThrow(InvalidConfiguration::class, message: $message);
    }

    /**
     * @return iterable<string, array{\Closure(): void, non-empty-string}>
     */
    public static function invalidArtifactCounts(): iterable
    {
        yield 'zero per-test count' => [
            static function (): void {
                GreenlightConfig::create()->artifacts(
                    static fn(ArtifactBuilder $artifacts) => $artifacts->maxAttachmentsPerTest(0), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
                );
            },
            'Artifact count per test must be at least 1.',
        ];
        yield 'negative per-test count' => [
            static function (): void {
                GreenlightConfig::create()->artifacts(
                    static fn(ArtifactBuilder $artifacts) => $artifacts->maxAttachmentsPerTest(-1), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
                );
            },
            'Artifact count per test must be at least 1.',
        ];
        yield 'zero per-run count' => [
            static function (): void {
                GreenlightConfig::create()->artifacts(
                    static fn(ArtifactBuilder $artifacts) => $artifacts->maxRunAttachments(0), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
                );
            },
            'Artifact count per run must be at least 1.',
        ];
        yield 'negative per-run count' => [
            static function (): void {
                GreenlightConfig::create()->artifacts(
                    static fn(ArtifactBuilder $artifacts) => $artifacts->maxRunAttachments(-1), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
                );
            },
            'Artifact count per run must be at least 1.',
        ];
    }
}

final class ConfigRunSubscriber implements RunLifecycleSubscriber
{
    #[\Override]
    public function onRunEvent(Event $event): void {}
}

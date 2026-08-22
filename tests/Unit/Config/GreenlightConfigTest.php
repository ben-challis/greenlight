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
use Greenlight\Config\SuiteBuilder;
use Greenlight\Config\WatchBuilder;
use Greenlight\Core\Event\Event;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\RunLifecycleSubscriber;

final class GreenlightConfigTest
{
    #[Test]
    public function buildsDocumentedDefaults(): void
    {
        $configuration = GreenlightConfig::create()->build();

        Expect::that($configuration->discovery->paths)->because('builds documented defaults')->toBe(['tests']);
        Expect::that($configuration->discovery->suites)->because('builds documented defaults')->toBe([]);
        Expect::that($configuration->workers->count->isAuto())->because('builds documented defaults')->toBeTrue();
        Expect::that($configuration->workers->recycleAfterTests)->because('builds documented defaults')->toBe(null);
        Expect::that($configuration->workers->recycleAboveMemoryBytes)->because('builds documented defaults')->toBe(268435456);
        Expect::that($configuration->coverage)->because('builds documented defaults')->toBe(null);
        Expect::that($configuration->execution->plugins)->because('builds documented defaults')->toBe([]);
        Expect::that($configuration->execution->stopAfterFailures)->because('builds documented defaults')->toBe(null);
        Expect::that($configuration->order->randomized)->because('builds documented defaults')->toBe(false);
        Expect::that($configuration->order->seed)->because('builds documented defaults')->toBe(null);
        Expect::that($configuration->execution->artifacts->directory)->because('builds documented defaults')->toBe('build/greenlight-artifacts');
        Expect::that($configuration->execution->artifacts->maxAttachmentsPerTest)->because('builds documented defaults')->toBe(32);
        Expect::that($configuration->workers->resourceLimits)->because('builds documented defaults')->toBe([]);
    }

    #[Test]
    public function retainsAZeroTestPath(): void
    {
        $configuration = GreenlightConfig::create()
            ->paths(['0'])
            ->build();

        Expect::that($configuration->discovery->paths)
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
            ->workers(count: 8, recycleAfterTests: 250, recycleAboveMemory: '1G')
            ->resourceLimit('postgres', 3)
            ->resourceLimit('payments-sandbox')
            ->coverage(static fn(CoverageBuilder $coverage) => $coverage->include('src')->driver('pcov')->export('lcov', 'coverage/lcov.info'))
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

        Expect::that($configuration->discovery->paths)->because('builds a fully configured run')->toBe(['tests/Unit', 'tests/Integration']);
        Expect::that($configuration->discovery->suites)->because('builds a fully configured run')->toHaveCount(2);
        Expect::that($configuration->discovery->suites[0]->name)->because('builds a fully configured run')->toBe('unit');
        Expect::that($configuration->discovery->suites[1]->paths)->because('builds a fully configured run')->toBe(['tests/Integration']);
        Expect::that($configuration->discovery->suites[1]->tags)->because('builds a fully configured run')->toBe(['io', 'slow']);
        Expect::that($configuration->workers->count->fixed)->because('builds a fully configured run')->toBe(8);
        Expect::that($configuration->workers->recycleAfterTests)->because('builds a fully configured run')->toBe(250);
        Expect::that($configuration->workers->recycleAboveMemoryBytes)->because('builds a fully configured run')->toBe(1073741824);
        $coverage = $configuration->coverage;

        Expect::that($coverage)
            ->because('GreenlightConfig::build() MUST return a CoverageConfiguration')
            ->toBeInstanceOf(CoverageConfiguration::class);

        Expect::that($coverage->includePaths)->because('builds a fully configured run')->toBe(['src']);
        Expect::that($coverage->driver)->because('builds a fully configured run')->toBe('pcov');
        Expect::that($coverage->exports[0]->format)->because('builds a fully configured run')->toBe('lcov');
        Expect::that($coverage->exports[0]->target)->because('builds a fully configured run')->toBe('coverage/lcov.info');
        Expect::that($configuration->execution->plugins)->because('builds a fully configured run')->toHaveCount(1);
        Expect::that($configuration->execution->plugins[0]->pluginClass)->because('builds a fully configured run')->toBe(ConfigRunSubscriber::class);
        Expect::that($configuration->execution->stopAfterFailures)->because('builds a fully configured run')->toBe(1);
        Expect::that($configuration->order->randomized)->because('builds a fully configured run')->toBe(true);
        Expect::that($configuration->order->seed)->because('builds a fully configured run')->toBe(99);
        Expect::that($configuration->execution->artifacts->directory)->because('builds a fully configured run')->toBe('build/evidence');
        Expect::that($configuration->execution->artifacts->maxAttachmentBytes)->because('builds a fully configured run')->toBe(5 * 1024 * 1024);
        Expect::that($configuration->execution->artifacts->maxRunBytes)->because('builds a fully configured run')->toBe(100 * 1024 * 1024);
        Expect::that($configuration->workers->resourceLimits)->because('builds a fully configured run')->toBe(['postgres' => 3, 'payments-sandbox' => 1]);
    }

    #[Test]
    public function preservesAZeroStringSuiteNameThroughBuild(): void
    {
        $configuration = GreenlightConfig::create()
            ->suite('0', static fn(SuiteBuilder $suite) => $suite->in('tests')->tag('fast'))
            ->build();

        Expect::that($configuration->discovery->suites[0]->name)
            ->because('a zero-string suite name is not empty')
            ->toBe('0');
        Expect::that($configuration->discovery->suites[0]->paths)
            ->because('the suite MUST retain its configured paths')
            ->toBe(['tests']);
        Expect::that($configuration->discovery->suites[0]->tags)
            ->because('the suite MUST retain its configured tags')
            ->toBe(['fast']);
    }

    #[Test]
    public function randomizeOrderWithoutSeedStillEnablesRandomization(): void
    {
        $configuration = GreenlightConfig::create()->randomizeOrder()->build();

        Expect::that($configuration->order->randomized)->because('randomize order without seed still enables randomization')->toBe(true);
        Expect::that($configuration->order->seed)->because('randomize order without seed still enables randomization')->toBe(null);
    }

    #[Test]
    public function rejectedWorkerConfigurationsDoNotPartiallyChangeTheBuilder(): void
    {
        $builder = GreenlightConfig::create()->workers(
            count: 2,
            recycleAfterTests: 10,
            recycleAboveMemory: '64M',
        );

        Expect::that(static fn(): GreenlightConfig => $builder->workers(
            count: 8,
            recycleAfterTests: 0, // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            recycleAboveMemory: '128M',
        ))
            ->because('a rejected worker configuration does not partially change the builder')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'recycleAfterTests must be at least 1, got 0.',
            );

        Expect::that(static fn(): GreenlightConfig => $builder->workers(
            count: 16,
            recycleAfterTests: 20,
            recycleAboveMemory: 'lots',
        ))
            ->because('an invalid memory limit does not partially change the builder')
            ->toThrow(
                InvalidConfiguration::class,
                message: 'Invalid memory size "lots". Use a positive byte count or a K, M, or G suffix, for example "256M".',
            );

        $configuration = $builder->build();

        Expect::that($configuration->workers->count->fixed)
            ->because('a rejected worker configuration retains the prior worker count')
            ->toBe(2);
        Expect::that($configuration->workers->recycleAfterTests)
            ->because('a rejected worker configuration retains the prior test limit')
            ->toBe(10);
        Expect::that($configuration->workers->recycleAboveMemoryBytes)
            ->because('a rejected worker configuration retains the prior memory limit')
            ->toBe(64 * 1024 * 1024);
    }

    #[Test]
    public function rejectedNestedConfigurationsDoNotPartiallyChangeTheBuilder(): void
    {
        $builder = GreenlightConfig::create()
            ->coverage(static fn(CoverageBuilder $coverage) => $coverage
                ->include('src')
                ->driver('pcov'))
            ->watch(static fn(WatchBuilder $watch) => $watch->debounceMilliseconds(500))
            ->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts->directory('build/original'));

        Expect::that(static fn(): GreenlightConfig => $builder->coverage(
            static fn(CoverageBuilder $coverage) => $coverage
                ->driver('xdebug')
                ->include(''), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        ))
            ->because('a rejected coverage configuration does not partially change the builder')
            ->toThrow(InvalidConfiguration::class);

        Expect::that(static fn(): GreenlightConfig => $builder->watch(
            static fn(WatchBuilder $watch) => $watch
                ->debounceMilliseconds(750)
                ->debounceMilliseconds(0), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        ))
            ->because('a rejected watch configuration does not partially change the builder')
            ->toThrow(InvalidConfiguration::class);

        Expect::that(static fn(): GreenlightConfig => $builder->artifacts(
            static fn(ArtifactBuilder $artifacts) => $artifacts
                ->directory('build/changed')
                ->maxRunAttachments(0), // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        ))
            ->because('a rejected artifact configuration does not partially change the builder')
            ->toThrow(InvalidConfiguration::class);

        $configuration = $builder->build();

        Expect::that($configuration->coverage?->includePaths)
            ->because('a rejected coverage configuration retains the prior include paths')
            ->toBe(['src']);
        Expect::that($configuration->coverage?->driver)
            ->because('a rejected coverage configuration retains the prior driver')
            ->toBe('pcov');
        Expect::that($configuration->watch->debounceMilliseconds)
            ->because('a rejected watch configuration retains the prior debounce')
            ->toBe(500);
        Expect::that($configuration->execution->artifacts->directory)
            ->because('a rejected artifact configuration retains the prior directory')
            ->toBe('build/original');
    }

    #[Test]
    public function rejectedDeprecationPatternsDoNotPartiallyChangeTheBuilder(): void
    {
        $builder = GreenlightConfig::create()->ignoreDeprecationsMatching('existing');

        Expect::that(static fn(): GreenlightConfig => $builder->ignoreDeprecationsMatching('added', '')) // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
            ->because('a rejected deprecation pattern does not partially change the builder')
            ->toThrow(InvalidConfiguration::class);

        Expect::that($builder->build()->execution->policy->ignoreDeprecations)
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
        Expect::that($callable)->toThrow(InvalidConfiguration::class);
    }

    /** @param array<mixed> $paths */
    #[Test]
    #[DataSet('invalidPaths')]
    public function invalidPathsGiveExactGuidance(array $paths, string $message): void
    {
        Expect::that(static function () use ($paths): void {
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
        Expect::that($configure)
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
        Expect::that($configure)
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

        yield 'zero recycleAfterTests' => [static function (): void {
            GreenlightConfig::create()->workers(recycleAfterTests: 0); // @phpstan-ignore argument.type (deliberately invalid: tests runtime validation)
        }];

        yield 'bad memory string' => [static function (): void {
            GreenlightConfig::create()->workers(recycleAboveMemory: 'lots');
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
        Expect::that($configure)
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

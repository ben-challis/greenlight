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
use Greenlight\Core\Event\Event;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Plugin\RunLifecycleSubscriber;

final class GreenlightConfigTest
{
    #[Test]
    public function buildsDocumentedDefaults(): void
    {
        $configuration = GreenlightConfig::create()->build();

        Expect::that($configuration->paths)->because('builds documented defaults')->toBe(['tests']);
        Expect::that($configuration->suites)->because('builds documented defaults')->toBe([]);
        Expect::that($configuration->workers->isAuto())->because('builds documented defaults')->toBeTrue();
        Expect::that($configuration->recycleAfterTests)->because('builds documented defaults')->toBe(null);
        Expect::that($configuration->recycleAboveMemoryBytes)->because('builds documented defaults')->toBe(268435456);
        Expect::that($configuration->coverage)->because('builds documented defaults')->toBe(null);
        Expect::that($configuration->plugins)->because('builds documented defaults')->toBe([]);
        Expect::that($configuration->stopAfterFailures)->because('builds documented defaults')->toBe(null);
        Expect::that($configuration->randomizeOrder)->because('builds documented defaults')->toBe(false);
        Expect::that($configuration->randomSeed)->because('builds documented defaults')->toBe(null);
        Expect::that($configuration->groups)->because('builds documented defaults')->toBe([]);
        Expect::that($configuration->artifacts->directory)->because('builds documented defaults')->toBe('build/greenlight-artifacts');
        Expect::that($configuration->artifacts->maxAttachmentsPerTest)->because('builds documented defaults')->toBe(32);
        Expect::that($configuration->resourceLimits)->because('builds documented defaults')->toBe([]);
    }

    #[Test]
    public function buildsAFullyConfiguredRun(): void
    {
        $plugin = new class implements RunLifecycleSubscriber {
            #[\Override]
            public function onRunEvent(Event $event): void {}
        };

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

        Expect::that($configuration->paths)->because('builds a fully configured run')->toBe(['tests/Unit', 'tests/Integration']);
        Expect::that($configuration->suites)->because('builds a fully configured run')->toHaveCount(2);
        Expect::that($configuration->suites[0]->name)->because('builds a fully configured run')->toBe('unit');
        Expect::that($configuration->suites[1]->paths)->because('builds a fully configured run')->toBe(['tests/Integration']);
        Expect::that($configuration->suites[1]->tags)->because('builds a fully configured run')->toBe(['io', 'slow']);
        Expect::that($configuration->workers->fixed)->because('builds a fully configured run')->toBe(8);
        Expect::that($configuration->recycleAfterTests)->because('builds a fully configured run')->toBe(250);
        Expect::that($configuration->recycleAboveMemoryBytes)->because('builds a fully configured run')->toBe(1073741824);
        $coverage = $configuration->coverage;

        if (!$coverage instanceof CoverageConfiguration) {
            Fail::because(\sprintf(
                'Expected GreenlightConfig::build() to return a CoverageConfiguration, got %s.',
                \get_debug_type($coverage),
            ));
        }

        Expect::that($coverage->includePaths)->because('builds a fully configured run')->toBe(['src']);
        Expect::that($coverage->driver)->because('builds a fully configured run')->toBe('pcov');
        Expect::that($coverage->exports[0]->format)->because('builds a fully configured run')->toBe('lcov');
        Expect::that($coverage->exports[0]->target)->because('builds a fully configured run')->toBe('coverage/lcov.info');
        Expect::that($configuration->plugins)->because('builds a fully configured run')->toBe([$plugin]);
        Expect::that($configuration->stopAfterFailures)->because('builds a fully configured run')->toBe(1);
        Expect::that($configuration->randomizeOrder)->because('builds a fully configured run')->toBe(true);
        Expect::that($configuration->randomSeed)->because('builds a fully configured run')->toBe(99);
        Expect::that($configuration->artifacts->directory)->because('builds a fully configured run')->toBe('build/evidence');
        Expect::that($configuration->artifacts->maxAttachmentBytes)->because('builds a fully configured run')->toBe(5 * 1024 * 1024);
        Expect::that($configuration->artifacts->maxRunBytes)->because('builds a fully configured run')->toBe(100 * 1024 * 1024);
        Expect::that($configuration->resourceLimits)->because('builds a fully configured run')->toBe(['postgres' => 3, 'payments-sandbox' => 1]);
    }

    #[Test]
    public function randomizeOrderWithoutSeedStillEnablesRandomization(): void
    {
        $configuration = GreenlightConfig::create()->randomizeOrder()->build();

        Expect::that($configuration->randomizeOrder)->because('randomize order without seed still enables randomization')->toBe(true);
        Expect::that($configuration->randomSeed)->because('randomize order without seed still enables randomization')->toBe(null);
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

    /**
     * @return iterable<string, array{\Closure(): void}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'empty paths' => [static function (): void {
            GreenlightConfig::create()->paths([]);
        }];

        yield 'empty path string' => [static function (): void {
            GreenlightConfig::create()->paths(['']);
        }];

        yield 'empty suite name' => [static function (): void {
            GreenlightConfig::create()->suite('', static fn(SuiteBuilder $suite) => $suite->in('tests'));
        }];

        yield 'duplicate suite' => [static function (): void {
            GreenlightConfig::create()
                ->suite('unit', static fn(SuiteBuilder $suite) => $suite->in('tests'))
                ->suite('unit', static fn(SuiteBuilder $suite) => $suite->in('tests'));
        }];

        yield 'suite without paths' => [static function (): void {
            GreenlightConfig::create()
                ->suite('unit', static function (SuiteBuilder $suite): void {})
                ->build();
        }];

        yield 'empty suite path' => [static function (): void {
            GreenlightConfig::create()->suite('unit', static fn(SuiteBuilder $suite) => $suite->in(''));
        }];

        yield 'empty suite tag' => [static function (): void {
            GreenlightConfig::create()->suite(
                'unit',
                static fn(SuiteBuilder $suite) => $suite->in('tests')->tag(''),
            );
        }];

        yield 'zero workers' => [static function (): void {
            GreenlightConfig::create()->workers(count: 0);
        }];

        yield 'bad worker string' => [static function (): void {
            // Reflection bypasses the static 'auto'|int type and exercises the
            // runtime guard.
            new \ReflectionMethod(GreenlightConfig::class, 'workers')
                ->invoke(GreenlightConfig::create(), 'many');
        }];

        yield 'zero recycleAfterTests' => [static function (): void {
            GreenlightConfig::create()->workers(recycleAfterTests: 0);
        }];

        yield 'bad memory string surfaces at build' => [static function (): void {
            GreenlightConfig::create()->workers(recycleAboveMemory: 'lots')->build();
        }];

        yield 'empty artifact directory' => [static function (): void {
            GreenlightConfig::create()->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts->directory(''));
        }];

        yield 'zero artifact count' => [static function (): void {
            GreenlightConfig::create()->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts->maxAttachmentsPerTest(0));
        }];

        yield 'invalid resource name' => [static function (): void {
            GreenlightConfig::create()->resourceLimit('Postgres');
        }];

        yield 'zero resource limit' => [static function (): void {
            GreenlightConfig::create()->resourceLimit('postgres', 0);
        }];

        yield 'duplicate resource limit' => [static function (): void {
            GreenlightConfig::create()->resourceLimit('postgres')->resourceLimit('postgres', 2);
        }];
    }

    #[Test]
    public function badMemoryStringIsAcceptedUntilBuild(): void
    {
        $builder = GreenlightConfig::create()->workers(recycleAboveMemory: 'lots');

        Expect::that(static function () use ($builder): void {
            $builder->build();
        })->because('bad memory string is accepted until build')->toThrow(InvalidConfiguration::class);
    }
}

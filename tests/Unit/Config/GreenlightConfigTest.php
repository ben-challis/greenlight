<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

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

        Expect::that($configuration->paths)->toBe(['tests']);
        Expect::that($configuration->suites)->toBe([]);
        Expect::that($configuration->workers->isAuto())->toBeTrue();
        Expect::that($configuration->recycleAfterTests)->toBe(null);
        Expect::that($configuration->recycleAboveMemoryBytes)->toBe(268435456);
        Expect::that($configuration->coverage)->toBe(null);
        Expect::that($configuration->plugins)->toBe([]);
        Expect::that($configuration->stopAfterFailures)->toBe(null);
        Expect::that($configuration->randomizeOrder)->toBe(false);
        Expect::that($configuration->randomSeed)->toBe(null);
        Expect::that($configuration->groups)->toBe([]);
        Expect::that($configuration->artifacts->directory)->toBe('build/greenlight-artifacts');
        Expect::that($configuration->artifacts->maxAttachmentsPerTest)->toBe(32);
        Expect::that($configuration->resourceLimits)->toBe([]);
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

        Expect::that($configuration->paths)->toBe(['tests/Unit', 'tests/Integration']);
        Expect::that($configuration->suites)->toHaveCount(2);
        Expect::that($configuration->suites[0]->name)->toBe('unit');
        Expect::that($configuration->suites[1]->paths)->toBe(['tests/Integration']);
        Expect::that($configuration->suites[1]->tags)->toBe(['io', 'slow']);
        Expect::that($configuration->workers->fixed)->toBe(8);
        Expect::that($configuration->recycleAfterTests)->toBe(250);
        Expect::that($configuration->recycleAboveMemoryBytes)->toBe(1073741824);
        $coverage = $configuration->coverage;

        if (!$coverage instanceof CoverageConfiguration) {
            Fail::because(\sprintf(
                'Expected GreenlightConfig::build() to return a CoverageConfiguration, got %s.',
                \get_debug_type($coverage),
            ));
        }

        Expect::that($coverage->includePaths)->toBe(['src']);
        Expect::that($coverage->driver)->toBe('pcov');
        Expect::that($coverage->exports[0]->format)->toBe('lcov');
        Expect::that($coverage->exports[0]->target)->toBe('coverage/lcov.info');
        Expect::that($configuration->plugins)->toBe([$plugin]);
        Expect::that($configuration->stopAfterFailures)->toBe(1);
        Expect::that($configuration->randomizeOrder)->toBe(true);
        Expect::that($configuration->randomSeed)->toBe(99);
        Expect::that($configuration->artifacts->directory)->toBe('build/evidence');
        Expect::that($configuration->artifacts->maxAttachmentBytes)->toBe(5 * 1024 * 1024);
        Expect::that($configuration->artifacts->maxRunBytes)->toBe(100 * 1024 * 1024);
        Expect::that($configuration->resourceLimits)->toBe(['postgres' => 3, 'payments-sandbox' => 1]);
    }

    #[Test]
    public function randomizeOrderWithoutSeedStillEnablesRandomization(): void
    {
        $configuration = GreenlightConfig::create()->randomizeOrder()->build();

        Expect::that($configuration->randomizeOrder)->toBe(true);
        Expect::that($configuration->randomSeed)->toBe(null);
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
            'empty artifact directory' => static function (): void {
                GreenlightConfig::create()->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts->directory(''));
            },
            'zero artifact count' => static function (): void {
                GreenlightConfig::create()->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts->maxAttachmentsPerTest(0));
            },
            'invalid resource name' => static function (): void {
                GreenlightConfig::create()->resourceLimit('Postgres');
            },
            'zero resource limit' => static function (): void {
                GreenlightConfig::create()->resourceLimit('postgres', 0);
            },
            'duplicate resource limit' => static function (): void {
                GreenlightConfig::create()->resourceLimit('postgres')->resourceLimit('postgres', 2);
            },
        ];

        foreach ($invalid as $callable) {
            Expect::that($callable)->toThrow(InvalidConfiguration::class);
        }
    }

    #[Test]
    public function badMemoryStringIsAcceptedUntilBuild(): void
    {
        $builder = GreenlightConfig::create()->workers(recycleAboveMemory: 'lots');

        Expect::that(static function () use ($builder): void {
            $builder->build();
        })->toThrow(InvalidConfiguration::class);
    }
}

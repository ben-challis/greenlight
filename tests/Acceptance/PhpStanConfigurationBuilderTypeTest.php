<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpStanProbe;

#[RequiresResource('analysis-process')]
final readonly class PhpStanConfigurationBuilderTypeTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function builderArgumentsExposeTheirRuntimeConstraints(): void
    {
        $probe = PhpStanProbe::analyze(
            $this->tempDirectory,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\ArtifactBuilder;
            use Greenlight\Config\CoverageBuilder;
            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Config\StorageBuilder;
            use Greenlight\Config\SuiteBuilder;
            use Greenlight\Config\WatchBuilder;

            function greenlightGoodConfigurationBuilderTypeProbe(): void
            {
                GreenlightConfig::create()
                    ->paths(['tests'])
                    ->suite('unit', static fn(SuiteBuilder $suite) => $suite->in('tests')->tag('fast'))
                    ->workers(count: 2, recycleAfterTests: 100, recycleAboveMemory: '256M')
                    ->resourceLimit('database', 1)
                    ->ignoreDeprecationsMatching('vendor *')
                    ->coverage(static fn(CoverageBuilder $coverage) => $coverage
                        ->include('src')
                        ->driver('pcov')
                        ->export('lcov', 'build/coverage.lcov'))
                    ->watch(static fn(WatchBuilder $watch) => $watch->debounceMilliseconds(200))
                    ->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts
                        ->directory('build/artifacts')
                        ->maxAttachmentsPerTest(10)
                        ->maxAttachmentSize('1M')
                        ->maxTestSize('10M')
                        ->maxRunAttachments(100)
                        ->maxRunSize('100M'))
                    ->storage(static fn(StorageBuilder $storage) => $storage
                        ->rootDirectory('build/greenlight')
                        ->stateDirectory('state')
                        ->cacheDirectory('cache')
                        ->generatedCodeDirectory('code')
                        ->temporaryDirectory('tmp'));
            }
            PHP,
            <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\ArtifactBuilder;
            use Greenlight\Config\CoverageBuilder;
            use Greenlight\Config\GreenlightConfig;
            use Greenlight\Config\StorageBuilder;
            use Greenlight\Config\SuiteBuilder;
            use Greenlight\Config\WatchBuilder;

            function greenlightBadConfigurationBuilderTypeProbe(): void
            {
                GreenlightConfig::create()->paths([]);
                GreenlightConfig::create()->suite('', static fn(SuiteBuilder $suite) => $suite->in('tests'));
                GreenlightConfig::create()->workers(count: 0);
                GreenlightConfig::create()->workers(recycleAfterTests: 0);
                GreenlightConfig::create()->workers(recycleAboveMemory: '');
                GreenlightConfig::create()->resourceLimit('', 1);
                GreenlightConfig::create()->resourceLimit('database', 0);
                GreenlightConfig::create()->ignoreDeprecationsMatching('');

                new SuiteBuilder('unit')->in('')->tag('');
                new CoverageBuilder()->include('')->driver('')->export('', 'report');
                new CoverageBuilder()->export('xml', 'report.xml');
                new WatchBuilder()->debounceMilliseconds(0);
                new ArtifactBuilder()
                    ->directory('')
                    ->maxAttachmentsPerTest(0)
                    ->maxAttachmentSize('')
                    ->maxTestSize('')
                    ->maxRunAttachments(0)
                    ->maxRunSize('');
                new StorageBuilder()
                    ->rootDirectory('')
                    ->stateDirectory('')
                    ->cacheDirectory('')
                    ->generatedCodeDirectory('')
                    ->temporaryDirectory('');
            }
            PHP,
        );

        Expect::that($probe->exitCode)
            ->because('PHPStan rejects configuration values that cannot pass runtime validation')
            ->toBe(1);
        Expect::that($probe->goodPassed)->toBeTrue();
        Expect::that(\count($probe->errors))->toBe(26);
        Expect::that($probe->messages())->toContain('Greenlight\Config\GreenlightConfig::workers() expects');
        Expect::that($probe->messages())->toContain('Greenlight\Config\ArtifactBuilder::maxRunAttachments() expects');
        Expect::that($probe->messages())->toContain('Greenlight\Config\StorageBuilder::temporaryDirectory() expects');
    }
}

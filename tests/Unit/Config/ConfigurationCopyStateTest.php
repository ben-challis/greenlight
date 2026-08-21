<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactConfiguration;
use Greenlight\Config\Configuration;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\CoverageExport;
use Greenlight\Config\SuiteConfiguration;
use Greenlight\Config\WatchConfiguration;
use Greenlight\Config\WorkerCount;
use Greenlight\Core\Result\ResultPolicy;
use Greenlight\Expect\Expect;
use Greenlight\Plugin\PluginDefinition;
use Greenlight\Tests\Fixture\Plugins\NamedFakePlugin;

final class ConfigurationCopyStateTest
{
    /**
     * @param \Closure(Configuration): Configuration $mutate
     */
    #[Test]
    #[DataSet('selectionCopies')]
    public function selectionCopiesChangeOnlyTheirTarget(
        \Closure $mutate,
        string $target,
        mixed $replacement,
    ): void {
        $original = $this->configuration();
        $originalState = \get_object_vars($original);
        $expectedState = $originalState;
        $expectedState[$target] = $replacement;

        $copy = $mutate($original);

        Expect::that($copy)
            ->because('a configuration copy MUST be a new value')
            ->not()
            ->toBe($original);
        Expect::that(\get_object_vars($copy))
            ->because('a configuration copy MUST change only its requested selection')
            ->toBe($expectedState);
        Expect::that(\get_object_vars($original))
            ->because('a configuration copy MUST leave its source unchanged')
            ->toBe($originalState);
    }

    /**
     * @return iterable<string, array{\Closure(Configuration): Configuration, non-empty-string, mixed}>
     */
    public static function selectionCopies(): iterable
    {
        $onlyTests = ['App\\ReplacementTest::runs'];

        yield 'only test IDs' => [
            static fn(Configuration $configuration): Configuration => $configuration
                ->withOnlyTests($onlyTests),
            'onlyTests',
            $onlyTests,
        ];

        $excludePaths = ['/project/replacement'];

        yield 'excluded paths' => [
            static fn(Configuration $configuration): Configuration => $configuration
                ->withExcludePaths($excludePaths),
            'excludePaths',
            $excludePaths,
        ];
    }

    private function configuration(): Configuration
    {
        $plugin = new PluginDefinition(
            NamedFakePlugin::class,
            static fn(): NamedFakePlugin => new NamedFakePlugin(),
        );

        return new Configuration(
            paths: ['/project/tests'],
            suites: [
                new SuiteConfiguration(
                    'integration',
                    ['/project/tests/Integration'],
                    ['database'],
                ),
            ],
            workers: WorkerCount::exactly(2),
            recycleAfterTests: 3,
            recycleAboveMemoryBytes: 4_096,
            coverage: new CoverageConfiguration(
                ['/project/src'],
                'xdebug',
                [new CoverageExport('json', '/project/build/coverage.json')],
            ),
            watch: new WatchConfiguration(750),
            plugins: [$plugin],
            policy: new ResultPolicy(
                failOnDeprecation: true,
                failOnNotice: true,
                ignoreDeprecations: ['legacy'],
                failOnRisky: true,
            ),
            stopAfterFailures: 4,
            randomizeOrder: true,
            randomSeed: 1_234,
            groups: ['fast'],
            filters: ['*Invoice*'],
            onlyTests: ['App\\OriginalTest::runs'],
            shard: [2, 3],
            excludeGroups: ['slow'],
            excludeClasses: ['Legacy*'],
            excludeMethods: ['flaky*'],
            excludePaths: ['/project/generated'],
            artifacts: new ArtifactConfiguration(
                directory: '/project/build/artifacts',
                maxAttachmentsPerTest: 5,
                maxAttachmentBytes: 1_024,
                maxTestBytes: 4_096,
                maxRunAttachments: 100,
                maxRunBytes: 8_192,
            ),
            resourceLimits: ['database' => 2],
        );
    }
}

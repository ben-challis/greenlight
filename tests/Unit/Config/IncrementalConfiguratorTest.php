<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\Test;
use Greenlight\Config\ArtifactBuilder;
use Greenlight\Config\CoverageBuilder;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\CoverageExport;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;

final class IncrementalConfiguratorTest
{
    #[Test]
    public function repeatedCoverageBlocksComposeOnOneBuilder(): void
    {
        $configuration = GreenlightConfig::create()
            ->coverage(static fn(CoverageBuilder $coverage) => $coverage
                ->include('src')
                ->driver('pcov')
                ->export('lcov', 'build/coverage.lcov'))
            ->coverage(static fn(CoverageBuilder $coverage) => $coverage
                ->include('packages')
                ->export('html', 'build/html'))
            ->build();
        $coverage = $configuration->coverage;

        if (!$coverage instanceof CoverageConfiguration) {
            Fail::because('Expected repeated coverage blocks to enable coverage.');
        }

        Expect::that($coverage->includePaths)
            ->because('repeated coverage blocks MUST retain earlier settings')
            ->toBe(['src', 'packages'])
            ->and($coverage->driver)
            ->toBe('pcov')
            ->and(\array_map(
                static fn(CoverageExport $export): array => [$export->format, $export->target],
                $coverage->exports,
            ))
            ->toBe([
                ['lcov', 'build/coverage.lcov'],
                ['html', 'build/html'],
            ]);
    }

    #[Test]
    public function repeatedArtifactBlocksComposeOnOneBuilder(): void
    {
        $artifacts = GreenlightConfig::create()
            ->artifacts(static fn(ArtifactBuilder $builder) => $builder
                ->directory('build/evidence')
                ->maxAttachmentsPerTest(12))
            ->artifacts(static fn(ArtifactBuilder $builder) => $builder
                ->maxAttachmentSize('2M')
                ->maxRunAttachments(200))
            ->build()
            ->artifacts;

        Expect::that([
            $artifacts->directory,
            $artifacts->maxAttachmentsPerTest,
            $artifacts->maxAttachmentBytes,
            $artifacts->maxRunAttachments,
        ])
            ->because('repeated artifact blocks MUST retain earlier settings')
            ->toBe(['build/evidence', 12, 2 * 1024 * 1024, 200]);
    }
}

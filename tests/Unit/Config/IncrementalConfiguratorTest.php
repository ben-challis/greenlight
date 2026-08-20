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

        Expect::that($coverage)
            ->because('Repeated coverage blocks MUST enable coverage.')
            ->toBeInstanceOf(CoverageConfiguration::class);

        Expect::that($coverage->includePaths)
            ->because('repeated coverage blocks MUST retain earlier settings')
            ->toBe(['src', 'packages']);
        Expect::that($coverage->driver)
            ->toBe('pcov');
        Expect::that(\array_map(
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

        Expect::that($artifacts->directory)
            ->because('repeated artifact blocks MUST retain the earlier directory')
            ->toBe('build/evidence');
        Expect::that($artifacts->maxAttachmentsPerTest)
            ->because('repeated artifact blocks MUST retain the earlier per-test limit')
            ->toBe(12);
        Expect::that($artifacts->maxAttachmentBytes)
            ->because('repeated artifact blocks MUST apply the attachment size limit')
            ->toBe(2 * 1024 * 1024);
        Expect::that($artifacts->maxRunAttachments)
            ->because('repeated artifact blocks MUST apply the per-run limit')
            ->toBe(200);
    }
}

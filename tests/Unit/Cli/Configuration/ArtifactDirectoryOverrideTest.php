<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Configuration;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Configuration\CliOverrides;
use Greenlight\Cli\Configuration\ConfigurationResolver;
use Greenlight\Cli\Configuration\ExecutionOverrides;
use Greenlight\Config\ArtifactBuilder;
use Greenlight\Config\GreenlightConfig;
use Greenlight\Expect\Expect;

final class ArtifactDirectoryOverrideTest
{
    #[Test]
    public function directoryOverridePreservesConfiguredSafetyLimits(): void
    {
        $configuration = GreenlightConfig::create()
            ->artifacts(static fn(ArtifactBuilder $artifacts) => $artifacts
                ->directory('build/config-evidence')
                ->maxAttachmentsPerTest(7)
                ->maxAttachmentSize('11K')
                ->maxTestSize('13K')
                ->maxRunAttachments(17)
                ->maxRunSize('19K'))
            ->build();

        $resolved = ConfigurationResolver::resolve(
            $configuration,
            new CliOverrides(execution: new ExecutionOverrides(artifactsDirectory: 'build/cli-evidence')),
        );

        Expect::that($resolved->execution->artifacts->toWire())
            ->because('the CLI directory override MUST preserve configured artifact safety limits')
            ->toBe([
                'directory' => 'build/cli-evidence',
                'maxAttachmentsPerTest' => 7,
                'maxAttachmentBytes' => 11 * 1024,
                'maxTestBytes' => 13 * 1024,
                'maxRunAttachments' => 17,
                'maxRunBytes' => 19 * 1024,
            ]);
    }
}

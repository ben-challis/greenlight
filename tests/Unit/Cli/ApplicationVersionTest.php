<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Application;
use Greenlight\Expect\Expect;

final readonly class ApplicationVersionTest
{
    #[Test]
    public function applicationVersionMatchesTheComposerPackageVersion(): void
    {
        $metadata = \json_decode(
            (string) \file_get_contents(__DIR__ . '/../../../composer.json'),
            true,
            flags: \JSON_THROW_ON_ERROR,
        );

        Expect::that($metadata)
            ->toBeArray();
        Expect::that($metadata['version'] ?? null)
            ->toBe(Application::VERSION);
    }
}

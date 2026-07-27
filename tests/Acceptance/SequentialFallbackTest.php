<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class SequentialFallbackTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function disabledProcOpenFallsBackToInProcess(): void
    {
        // An isolated project prevents a conflict with another acceptance
        // test in the same directory.
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'sequential-fallback');
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--workers=4', '--reporter=plain'],
            phpArguments: ['-d', 'disable_functions=proc_open'],
        );
        Expect::that($result->exitCode)->because('disabled proc open falls back to in process')->toBe(0)
            ->and($result->output())->toContain('7 tests, 7 passed')
            ->not()->toContain('proc_open');
    }
}

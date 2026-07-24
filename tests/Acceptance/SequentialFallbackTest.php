<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

/**
 * Worker spawning needs proc_open, which restricted hosts disable. The run
 * must then complete in-process instead of fataling, even when workers were
 * requested explicitly.
 */
final readonly class SequentialFallbackTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function disabledProcOpenFallsBackToInProcess(): void
    {
        // A private copy of ListTestsConfig, so this run cannot race another
        // acceptance test's use of the same working directory.
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'sequential-fallback');
        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--workers=4', '--reporter=plain'],
            phpArguments: ['-d', 'disable_functions=proc_open'],
        );
        Expect::that($result->exitCode)->toBe(0)
            ->and($result->output())->toContain('7 tests, 7 passed')
            ->and($result->output())->not()->toContain('proc_open');
    }
}

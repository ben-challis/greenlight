<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CommandErrorsTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function unknownCommandExitsWithAUsageError(): void
    {
        $result = GreenlightCli::run(\dirname(__DIR__, 2), ['bogus-command']);

        Expect::that($result->exitCode)->toBe(64)
            ->and($result->output())->toContain("Unknown command 'bogus-command'")
            ->toContain('greenlight --help');
    }

    #[Test]
    public function coverageDiffWithoutBaselineOrCurrentIsAUsageError(): void
    {
        $result = GreenlightCli::run(\dirname(__DIR__, 2), ['coverage:diff']);

        Expect::that($result->exitCode)->toBe(64)
            ->and($result->output())->toContain('coverage:diff requires --baseline=<path> and --current=<path>');
    }

    #[Test]
    public function profileReportWithAMissingInputFileFailsCleanly(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'command-errors');
        $result = GreenlightCli::run($project->directory, ['profile:report', '--input=nowhere.jsonl']);
        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('Could not read')
            ->toContain('nowhere.jsonl');
    }

    #[Test]
    public function ideHelperWithAnUnwritableOutputPathFailsCleanly(): void
    {
        // Root bypasses directory write permissions, so chmod 0555 cannot
        // provoke the write failure.
        if (\function_exists('posix_getuid') && \posix_getuid() === 0) {
            throw new SkipTest('An unwritable directory cannot be staged when running as root.');
        }

        // A config without any matchers configured skips writing entirely
        // (IdeHelperTest covers that path), so this needs the shipped
        // PhpStanExtension fixture, whose config has matchers to render.
        $fixture = \dirname(__DIR__) . '/Fixture/PhpStanExtension';
        $readOnlyDirectory = $this->tempDirectory->subdirectory('ide-helper-read-only');
        \chmod($readOnlyDirectory, 0o555);

        try {
            $result = GreenlightCli::run($fixture, [
                'ide-helper',
                '--output=' . $readOnlyDirectory . '/helper.php',
            ]);

            Expect::that($result->exitCode)->toBe(1)->and($result->output())->toContain('Could not write');
        } finally {
            \chmod($readOnlyDirectory, 0o755);
        }
    }
}

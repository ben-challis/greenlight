<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\SkipTest;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class CommandErrorsTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function unknownCommandExitsWithAUsageError(): void
    {
        $result = GreenlightCli::run(\dirname(__DIR__, 2), ['bogus-command']);

        Expect::that($result->exitCode)->because('unknown command exits with a usage error')->toBe(64);
        Expect::that($result->output())->toContain("Unknown command 'bogus-command'")
            ->toContain('greenlight --help');
    }

    #[Test]
    public function aListOptionDoesNotReplaceAnUnknownCommand(): void
    {
        $result = GreenlightCli::run(\dirname(__DIR__, 2), ['bogus-command', '--list-tests', '--no-ansi']);

        Expect::that($result->exitCode)
            ->because('a list option MUST not replace an explicit unknown command')
            ->toBe(64);
        Expect::that($result->stderr)
            ->toBe("greenlight: Unknown command 'bogus-command'. Use greenlight --help to list commands.");
        Expect::that($result->stdout)->toBe('');
    }

    #[Test]
    public function aListOptionDoesNotReplaceAnotherKnownCommand(): void
    {
        $result = GreenlightCli::run(\dirname(__DIR__, 2), ['coverage:diff', '--list-tests', '--no-ansi']);

        Expect::that($result->exitCode)
            ->because('a list option MUST not replace an explicit known command')
            ->toBe(64);
        Expect::that($result->stderr)
            ->toBe('coverage:diff requires --baseline=<path> and --current=<path>.');
        Expect::that($result->stdout)->toBe('');
    }

    #[Test]
    public function anInvalidRunOverrideExitsWithAUsageError(): void
    {
        $result = GreenlightCli::run(\dirname(__DIR__, 2), [
            'run',
            '--bail=0',
            '--no-ansi',
        ]);

        Expect::that($result->exitCode)
            ->because('an invalid run override MUST exit with a usage error')
            ->toBe(64);
        Expect::that($result->stderr)
            ->toBe('greenlight: --bail requires a positive integer. Received "0".');
    }

    #[Test]
    public function impactedWatchRequiresWatchMode(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'impacted-watch-requires-watch');
        $this->writeMinimalConfiguration($project);
        $result = GreenlightCli::run($project->directory, ['run', '--watch-impacted', '--no-ansi']);

        Expect::that($result->exitCode)->toBe(64);
        Expect::that($result->stderr)->toBe('greenlight: Use --watch-impacted with --watch.');
    }

    #[Test]
    public function impactedWatchRequiresACoverageIncludePath(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'impacted-watch-requires-coverage');
        $this->writeMinimalConfiguration($project);
        $result = GreenlightCli::run($project->directory, ['run', '--watch', '--watch-impacted', '--no-ansi']);

        Expect::that($result->exitCode)->toBe(64);
        Expect::that($result->stderr)->toBe('greenlight: Impacted watch requires at least one coverage include path.');
    }

    private function writeMinimalConfiguration(AcceptanceProject $project): void
    {
        $project->writeFile('greenlight.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            use Greenlight\Config\GreenlightConfig;

            return GreenlightConfig::create()->paths([__DIR__ . '/tests']);
            PHP);
    }

    #[Test]
    public function coverageDiffWithoutBaselineOrCurrentIsAUsageError(): void
    {
        $result = GreenlightCli::run(\dirname(__DIR__, 2), ['coverage:diff']);

        Expect::that($result->exitCode)->because('coverage diff without baseline or current is a usage error')->toBe(64);
        Expect::that($result->output())->toContain('coverage:diff requires --baseline=<path> and --current=<path>');
    }

    #[Test]
    public function profileReportWithoutInputIsAUsageError(): void
    {
        $result = GreenlightCli::run(\dirname(__DIR__, 2), ['profile:report']);

        Expect::that($result->exitCode)
            ->because('profile report without input is a usage error')
            ->toBe(64);
        Expect::that($result->stderr)
            ->toBe('profile:report requires --input=<path to a JSONL stream>.');
    }

    #[Test]
    public function profileReportWithAMissingInputFileFailsCleanly(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'command-errors');
        $result = GreenlightCli::run($project->directory, ['profile:report', '--input=nowhere.jsonl']);
        Expect::that($result->exitCode)->because('profile report with a missing input file fails cleanly')->toBe(1);
        Expect::that($result->output())->toContain('Greenlight could not read')
            ->toContain('nowhere.jsonl');
    }

    #[Test]
    public function ideHelperWithAnUnwritableOutputPathFailsCleanly(): void
    {
        // Root ignores directory write permissions. Thus, chmod 0555 cannot
        // cause the required write failure.
        if (\function_exists('posix_getuid') && \posix_getuid() === 0) {
            throw new SkipTest('An unwritable directory cannot be staged when running as root.');
        }

        // A configuration without matchers does not write a file.
        // IdeHelperTest verifies that path. This test uses PhpStanExtension,
        // which has matchers that the helper can render.
        $fixture = FixturePath::get('PhpStanExtension');
        $readOnlyDirectory = $this->tempDirectory->subdirectory('ide-helper-read-only');
        \chmod($readOnlyDirectory, 0o555);
        $outputPath = $readOnlyDirectory . '/helper.php';

        try {
            $result = GreenlightCli::run($fixture, [
                'ide-helper',
                '--output=' . $outputPath,
            ]);

            Expect::that($result->exitCode)->toBe(1);
            Expect::that($result->output())->toContain(\sprintf('Greenlight could not write "%s":', $outputPath));
        } finally {
            \chmod($readOnlyDirectory, 0o755);
        }
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
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
    #[DataSet('invalidProfileStreams')]
    public function profileReportRejectsInvalidEventStreams(
        string $projectName,
        string $stream,
        string $message,
    ): void {
        $project = AcceptanceProject::create($this->tempDirectory, $projectName);
        $project->writeFile('profile.jsonl', $stream);

        $result = GreenlightCli::run($project->directory, [
            'profile:report',
            '--input=profile.jsonl',
            '--no-ansi',
        ]);

        Expect::that($result->exitCode)
            ->because('an invalid profile stream fails cleanly')
            ->toBe(1);
        Expect::that($result->output())
            ->toContain($message);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string, non-empty-string}>
     */
    public static function invalidProfileStreams(): iterable
    {
        yield 'invalid JSON' => [
            'profile-invalid-json',
            '{',
            'A line is not valid JSON.',
        ];

        yield 'invalid envelope' => [
            'profile-invalid-envelope',
            '{"event":7,"data":[]}',
            'A line does not contain an event envelope.',
        ];

        yield 'invalid envelope key' => [
            'profile-invalid-envelope-key',
            '{"0":true,"v":2,"event":"future-event","data":{}}',
            'A line does not contain an event envelope.',
        ];

        yield 'unsupported envelope version' => [
            'profile-unsupported-version',
            '{"v":3,"event":"run-started","data":{}}',
            'The input uses unsupported JSONL version 3.',
        ];

        yield 'invalid known event payload' => [
            'profile-invalid-payload',
            '{"v":2,"event":"run-started","data":[]}',
            'Greenlight could not decode a "run-started" event:',
        ];

        yield 'invalid event data map' => [
            'profile-invalid-data-map',
            '{"v":2,"event":"future-event","data":{"0":true}}',
            'A line does not contain an event envelope.',
        ];

        yield 'no finished run' => [
            'profile-no-finished-run',
            '{"v":2,"event":"future-event","data":[]}',
            'The stream has no finished run to profile.',
        ];
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
        $fixture = \dirname(__DIR__) . '/Fixture/PhpStanExtension';
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

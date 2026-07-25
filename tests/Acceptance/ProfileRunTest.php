<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

/**
 * The profile through the real CLI.
 *
 * --profile appends the block after the summary, and profile:report
 * reproduces the same numbers offline from the jsonl artifact of the same
 * run.
 */
final readonly class ProfileRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function liveProfileAndOfflineReportAgree(): void
    {
        $root = \dirname(__DIR__, 2);
        // A private copy of ListTestsConfig, so this run cannot race another
        // acceptance test's use of the same working directory.
        $project = AcceptanceProject::copyOfListTestsConfig($this->tempDirectory, 'profile');
        $artifact = $project->path('profile.jsonl');

        $result = GreenlightCli::run(
            $project->directory,
            ['run', '--workers=2', '--reporter=plain', '--reporter=jsonl', '--profile'],
        );
        $output = $result->stdoutLines();
        $live = $result->stdout;

        Expect::that($result->exitCode)->toBe(0)
            ->and($live)->toContain('Profile:')
            ->toContain('spawned, 0 recycled')
            ->toContain('Boot latency:')
            ->toContain('Slowest classes:');

        // The jsonl lines are interleaved with the plain report on
        // stdout; extract them into an artifact file.
        $jsonl = \array_filter($output, static fn(string $line): bool => \str_starts_with($line, '{"v":'));
        \file_put_contents($artifact, \implode("\n", $jsonl) . "\n");

        // stdout only: extensions like ddtrace write noise to stderr on
        // spawn, and this comparison is exact.
        $report = GreenlightCli::run($root, ['profile:report', '--input=' . $artifact]);
        $offline = $report->stdout;

        // The live block, minus its leading blank line, must reproduce
        // verbatim from the artifact.
        $liveBlock = \substr($live, (int) \strpos($live, 'Profile:'));

        Expect::that($report->exitCode)->toBe(0)
            ->and($offline . "\n")->toBe($liveBlock . "\n");
    }
}

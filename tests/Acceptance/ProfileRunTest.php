<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class ProfileRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function liveProfileAndOfflineReportAgree(): void
    {
        $root = \dirname(__DIR__, 2);
        // An isolated project prevents a conflict with another acceptance
        // test in the same directory.
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'profile');
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

        // The plain report and JSONL lines share standard output. Extract the
        // JSONL lines to an artifact file.
        $jsonl = \array_filter($output, static fn(string $line): bool => \str_starts_with($line, '{"v":'));
        \file_put_contents($artifact, \implode("\n", $jsonl) . "\n");

        // Use standard output only because this comparison is exact.
        // Extensions such as ddtrace write messages to standard error.
        $report = GreenlightCli::run($root, ['profile:report', '--input=' . $artifact]);
        $offline = $report->stdout;

        // The artifact MUST reproduce the live block without its first blank
        // line.
        $liveBlock = \substr($live, (int) \strpos($live, 'Profile:'));

        Expect::that($report->exitCode)->toBe(0)
            ->and($offline . "\n")->toBe($liveBlock . "\n");
    }
}

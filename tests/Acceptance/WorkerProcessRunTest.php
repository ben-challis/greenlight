<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final readonly class WorkerProcessRunTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function parallelResultsMatchSequentialResults(): void
    {
        // An isolated project prevents a conflict with another acceptance
        // test in the same directory.
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'parallel');
        $sequential = GreenlightCli::run($project->directory, ['run', '--workers=1']);
        $parallel = GreenlightCli::run($project->directory, ['run', '--workers=3']);
        Expect::that($sequential->exitCode)->because('parallel results match sequential results')->toBe(0)
            ->and($parallel->exitCode)->toBe(0)
            ->and($this->summaryLine($sequential->output()))->toBe('7 tests, 7 passed, 0 expectations')
            ->and($this->summaryLine($parallel->output()))->toBe('7 tests, 7 passed, 0 expectations');
    }

    #[Test]
    public function configuredPluginsReachWorkersAcrossTheProcessBoundary(): void
    {
        $result = $this->runIn('PluginRunConfig', ['run', '--workers=2']);

        Expect::that($result->exitCode)->because('configured plugins reach workers across the process boundary')->toBe(0)
            ->and($this->summaryLine($result->output()))->toBe('2 tests, 1 passed, 1 skipped, 0 expectations');
    }

    #[Test]
    public function workerRecyclingKeepsResultsIntact(): void
    {
        $result = $this->runIn('RecycleConfig', ['run']);

        Expect::that($result->exitCode)->because('worker recycling keeps results intact')->toBe(0)
            ->and($this->summaryLine($result->output()))->toBe('7 tests, 7 passed, 0 expectations');
    }

    /**
     * @param list<string> $arguments
     */
    private function runIn(string $fixtureConfigDir, array $arguments): ProcessResult
    {
        return GreenlightCli::run(\dirname(__DIR__) . '/Fixture/' . $fixtureConfigDir, $arguments);
    }

    private function summaryLine(string $output): string
    {
        if (\preg_match('/^\d+ tests?, \d+ passed(?:, \d+ failed)?(?:, \d+ errored)?(?:, \d+ skipped)?, \d+ expectations?$/m', $output, $matches) !== 1) {
            Fail::because("No summary line found in output:\n" . $output);
        }

        return $matches[0];
    }
}

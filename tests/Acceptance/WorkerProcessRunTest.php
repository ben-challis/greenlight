<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\FixturePath;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final readonly class WorkerProcessRunTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function parallelResultsMatchSequentialResults(): void
    {
        // An isolated project prevents a conflict with another acceptance
        // test in the same directory.
        $project = AcceptanceProject::createWithDiscoveryBasicTests($this->tempDirectory, 'parallel');
        $sequential = GreenlightCli::run($project->directory, ['run', '--workers=1']);
        $parallel = GreenlightCli::run($project->directory, ['run', '--workers=3']);
        Expect::that($sequential->exitCode)->because('parallel results match sequential results')->toBe(0);
        Expect::that($parallel->exitCode)->toBe(0);
        Expect::that($this->summaryLine($sequential->output()))->toBe('7 tests, 7 passed, 0 expectations');
        Expect::that($this->summaryLine($parallel->output()))->toBe('7 tests, 7 passed, 0 expectations');
    }

    #[Test]
    public function configuredPluginsReachWorkersAcrossTheProcessBoundary(): void
    {
        $result = $this->runIn('PluginRunConfig', ['run', '--workers=2']);

        Expect::that($result->exitCode)->because('configured plugins reach workers across the process boundary')->toBe(0);
        Expect::that($this->summaryLine($result->output()))->toBe('2 tests, 1 passed, 1 skipped, 0 expectations');
    }

    /** @param list<string> $arguments */
    #[Test]
    #[DataSet('workerDisposalModes')]
    public function workerDisposalFailuresAreEquivalentAcrossExecutionModes(array $arguments): void
    {
        $result = $this->runIn('HarnessDisposalRun', $arguments);

        Expect::that($result->exitCode)
            ->because('worker disposal MUST make each execution mode unsuccessful')
            ->toBe(1);
        Expect::that($result->output())
            ->because('each execution mode MUST report the worker disposal failure')
            ->toContain('test broke first')
            ->toContain('Worker harness service disposal failed.')
            ->toContain('harness service disposal broke');
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function workerDisposalModes(): iterable
    {
        yield 'in-process' => [['run', '--workers=1']];
        yield 'parallel drain' => [['run', '--workers=2']];
    }

    /**
     * @param list<string> $arguments
     */
    private function runIn(string $fixtureConfigDir, array $arguments): ProcessResult
    {
        return GreenlightCli::run(FixturePath::get($fixtureConfigDir), $arguments);
    }

    private function summaryLine(string $output): string
    {
        if (\preg_match('/^\d+ tests?, \d+ passed(?:, \d+ failed)?(?:, \d+ errored)?(?:, \d+ skipped)?, \d+ expectations?$/m', $output, $matches) !== 1) {
            Fail::because("No summary line found in output:\n" . $output);
        }

        return $matches[0];
    }
}

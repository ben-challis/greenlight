<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;
use Greenlight\Tests\Support\ProcessResult;

/** Crash and hang fixtures MUST NOT run in the orchestrator process. */
final readonly class ParallelRunTest
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
        Expect::that($sequential->exitCode)->toBe(0)
            ->and($parallel->exitCode)->toBe(0)
            ->and($this->summaryLine($sequential->output()))->toBe('7 tests, 7 passed, 0 expectations')
            ->and($this->summaryLine($parallel->output()))->toBe('7 tests, 7 passed, 0 expectations');
    }

    #[Test]
    public function crashedWorkersAreContainedAndTheRunCompletes(): void
    {
        $result = $this->runIn('CrashConfig', ['run', '--workers=2']);

        Expect::that($result->exitCode)->toBe(1)
            ->and($this->summaryLine($result->output()))->toBe('3 tests, 2 passed, 1 errored, 0 expectations')
            ->and($result->output())->toContain('crashed while running');
    }

    #[Test]
    public function aCrashOnARetryPreservesTheAttemptCount(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'retry-crash');
        $project->writeFile('tests/RetryCrashTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace RetryCrash;

            use Greenlight\Attribute\Retry;
            use Greenlight\Attribute\Test;

            final class RetryCrashTest
            {
                #[Test]
                #[Retry(1)]
                public function crashesOnItsSecondAttempt(): never
                {
                    static $attempt = 0;
                    ++$attempt;

                    if ($attempt === 1) {
                        throw new \RuntimeException('retry me');
                    }

                    exit(23);
                }
            }
            PHP);
        $project->configureWithTestFiles(['tests/RetryCrashTest.php'], workers: 2);

        $result = GreenlightCli::run($project->directory, ['run', '--reporter=jsonl']);
        $finished = \array_find(
            JsonlEvents::from($result),
            static fn($event): bool => $event instanceof TestFinished,
        );

        if (!$finished instanceof TestFinished) {
            Fail::because('The crashed retry did not emit TestFinished.');
        }

        Expect::that($result->exitCode)->toBe(1)
            ->and($finished->result->outcome)->toBe(Outcome::Errored)
            ->and($finished->result->attempts)->toBe(2);
    }

    #[Test]
    public function configuredPluginsReachWorkersAcrossTheProcessBoundary(): void
    {
        $result = $this->runIn('PluginRunConfig', ['run', '--workers=2']);

        Expect::that($result->exitCode)->toBe(0)
            ->and($this->summaryLine($result->output()))->toBe('2 tests, 1 passed, 1 skipped, 0 expectations');
    }

    #[Test]
    public function workerRecyclingKeepsResultsIntact(): void
    {
        $result = $this->runIn('RecycleConfig', ['run']);

        Expect::that($result->exitCode)->toBe(0)
            ->and($this->summaryLine($result->output()))->toBe('7 tests, 7 passed, 0 expectations');
    }

    #[Test]
    public function leakDetectionNamesTheLeakAndFailsTheRun(): void
    {
        $withFlag = $this->runIn('LeakConfig', ['run', '--detect-leaks', '--workers=2']);

        Expect::that($withFlag->exitCode)->toBe(1)
            ->and($withFlag->output())->toContain('Leaks (the test instance survived its test):')
            ->toContain('  Greenlight\Tests\Fixture\LeakSuite\LeakyTest::passesButLeaksItself');

        $withoutFlag = $this->runIn('LeakConfig', ['run', '--workers=2']);

        Expect::that($withoutFlag->exitCode)->toBe(0);
    }

    #[Test]
    public function leakDetectionWarnsWhenXdebugDevelopModeIsActive(): void
    {
        if (!\extension_loaded('xdebug')) {
            // Xdebug develop mode causes the warning. The test cannot create
            // this environment property without the extension.
            throw new SkipTest('xdebug is not loaded');
        }

        $develop = $this->runIn('LeakConfig', ['run', '--detect-leaks', '--workers=2'], ['XDEBUG_MODE' => 'develop']);

        Expect::that($develop->output())->toContain('xdebug develop mode');

        $off = $this->runIn('LeakConfig', ['run', '--detect-leaks', '--workers=2'], ['XDEBUG_MODE' => 'off']);

        Expect::that($off->output())->not()->toContain('xdebug develop mode');
    }

    #[Test]
    public function hangingTestsAreHardKilledByTheOrchestrator(): void
    {
        $startedAt = \hrtime(true);
        $result = $this->runIn('HangConfig', ['run', '--workers=2', '--reporter=jsonl']);
        $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
        $events = JsonlEvents::from($result);
        $finished = \array_find($events, static fn($event): bool => $event instanceof TestFinished);

        if (!$finished instanceof TestFinished) {
            Fail::because('The hard timeout did not emit TestFinished.');
        }

        Expect::that($result->exitCode)->toBe(1)
            ->and($result->output())->toContain('timeout budget')
            ->and($durationSeconds)->toBeLessThan(20.0)
            ->and($finished->result->outcome)->toBe(Outcome::Failed)
            ->and($finished->result->durationSeconds)->toBeGreaterThan(0.1)
            ->and($finished->result->failures)->toHaveCount(1)
            ->and($finished->result->error)->toBeNull();
    }

    /**
     * @param list<string> $arguments
     * @param array<string, string> $env
     */
    private function runIn(string $fixtureConfigDir, array $arguments, array $env = []): ProcessResult
    {
        return GreenlightCli::run(\dirname(__DIR__) . '/Fixture/' . $fixtureConfigDir, $arguments, $env);
    }

    private function summaryLine(string $output): string
    {
        if (\preg_match('/^\d+ tests?, \d+ passed(?:, \d+ failed)?(?:, \d+ errored)?(?:, \d+ skipped)?, \d+ expectations?$/m', $output, $matches) !== 1) {
            Fail::because("No summary line found in output:\n" . $output);
        }

        return $matches[0];
    }
}

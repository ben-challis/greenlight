<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\Outcome;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\AcceptanceProject;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\JsonlEvents;
use Greenlight\Tests\Support\ProcessResult;

/** Crash and hang fixtures MUST NOT run in the orchestrator process. */
final readonly class WorkerFailureContainmentTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function crashedWorkersAreContainedAndTheRunCompletes(): void
    {
        $result = $this->runIn('CrashConfig', ['run', '--workers=2']);

        Expect::that($result->exitCode)->because('crashed workers are contained and the run completes')->toBe(1);
        Expect::that($this->summaryLine($result->output()))->toBe('3 tests, 2 passed, 1 errored, 0 expectations');
        Expect::that($result->output())->toContain('crashed during this test');
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

        Expect::that($finished)
            ->because('The crashed retry did not emit TestFinished.')
            ->toBeInstanceOf(TestFinished::class);

        Expect::that($result->exitCode)->because('a crash on a retry preserves the attempt count')->toBe(1);
        Expect::that($finished->result->outcome)->toBe(Outcome::Errored);
        Expect::that($finished->result->attempts)->toBe(2);
    }

    #[Test]
    public function hangingTestsAreHardKilledByTheOrchestrator(): void
    {
        $startedAt = \hrtime(true);
        $result = $this->runIn('HangConfig', ['run', '--workers=2', '--reporter=jsonl']);
        $durationSeconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
        $events = JsonlEvents::from($result);
        $finished = \array_find($events, static fn($event): bool => $event instanceof TestFinished);

        Expect::that($finished)
            ->because('The hard timeout did not emit TestFinished.')
            ->toBeInstanceOf(TestFinished::class);

        Expect::that($result->exitCode)->because('hanging tests are hard killed by the orchestrator')->toBe(1);
        Expect::that($result->output())->toContain('time limit');
        Expect::that($durationSeconds)->toBeLessThan(20.0);
        Expect::that($finished->result->outcome)->toBe(Outcome::Failed);
        Expect::that($finished->result->durationSeconds)->toBeGreaterThan(0.1);
        Expect::that($finished->result->failures)->toHaveCount(1);
        Expect::that($finished->result->error)->toBeNull();
    }

    #[Test]
    public function aHardTimeoutPreservesWorkerDiagnostics(): void
    {
        $project = AcceptanceProject::create($this->tempDirectory, 'timeout-diagnostics');
        $project->writeFile('tests/TimeoutDiagnosticsTest.php', <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace TimeoutDiagnostics;

            use Greenlight\Attribute\Test;
            use Greenlight\Attribute\Timeout;

            final class TimeoutDiagnosticsTest
            {
                #[Test]
                #[Timeout(0.05)]
                public function hangsAfterWritingDiagnostics(): void
                {
                    \fwrite(\STDERR, "The timed-out worker emitted diagnostics.\n");
                    \sleep(60);
                }
            }
            PHP);
        $project->configureWithTestFiles(['tests/TimeoutDiagnosticsTest.php'], workers: 2);

        $result = GreenlightCli::run($project->directory, ['run', '--workers=2', '--reporter=jsonl']);
        $finished = \array_find(
            JsonlEvents::from($result),
            static fn($event): bool => $event instanceof TestFinished,
        );

        Expect::that($finished)
            ->because('The diagnostic hard timeout did not emit TestFinished.')
            ->toBeInstanceOf(TestFinished::class);

        $failure = $finished->result->failures[0] ?? null;

        Expect::that($failure)
            ->because('The diagnostic hard timeout did not report a failure.')
            ->toBeInstanceOf(FailureDetail::class);

        Expect::that($result->exitCode)
            ->because('a hard timeout MUST fail the run')
            ->toBe(1);
        Expect::that($failure->message)
            ->because('a hard timeout MUST preserve the worker diagnostic output')
            ->toContain("Worker output:\nThe timed-out worker emitted diagnostics.");
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

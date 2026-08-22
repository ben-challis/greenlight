<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Runner;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\ExecutionPlan;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Result\ThrowableDetail;
use Greenlight\Runner\Worker\HarnessServiceDisposal;
use Greenlight\Runner\Worker\Worker;
use Greenlight\Runner\Worker\WorkerError;
use Greenlight\Runner\Worker\WorkerRunOutcome;
use Greenlight\Test\TestId;
use Greenlight\Tests\Fixture\HarnessDisposalMatrix\FailingHarnessService;
use Greenlight\Tests\Fixture\HarnessDisposalMatrix\HarnessDisposalMatrixTest;
use Greenlight\Tests\Support\CollectingEventSink;
use Greenlight\Tests\Support\PlanEntryFixture;

final readonly class HarnessServiceDisposalTest
{
    #[Test]
    public function everyDisposalFailureRemainsInOrderAfterAnExistingError(): void
    {
        $primary = ThrowableDetail::fromThrowable(new \RuntimeException('test broke first'));
        $result = new TestResult(
            new TestId(HarnessDisposalMatrixTest::class, 'errorsBeforeDisposal'),
            Outcome::Errored,
            0.0,
            0,
            error: $primary,
        );

        $reported = HarnessServiceDisposal::applyToTest($result, [
            new \RuntimeException('first disposal broke'),
            new \LogicException('second disposal broke'),
        ]);

        Expect::that($reported->error)
            ->because('the test error MUST remain primary')
            ->toBe($primary);
        Expect::that(\array_map(
            static fn($failure): string => $failure->message,
            $reported->failures,
        ))
            ->because('each disposal failure MUST remain in disposal order')
            ->toBe([
                'Harness service disposal caused an error: first disposal broke',
                'Harness service disposal caused an error: second disposal broke',
            ]);
    }

    /** @param non-empty-string $method */
    #[Test]
    #[DataSet('testScopeOutcomes')]
    public function perTestDisposalRetainsThePrimaryResult(string $method, Outcome $outcome): void
    {
        [$threw, $sink] = $this->run(Scope::PerTest, [$method]);
        $result = $sink->results()[0];

        Expect::that($threw)
            ->because('per-test disposal MUST remain in the test result')
            ->toBeNull();
        Expect::that($result->outcome)->toBe($outcome);

        if ($method === 'errorsBeforeDisposal') {
            Expect::that($result->error?->message)
                ->because('the test error MUST remain primary')
                ->toBe('test broke first');
            Expect::that($result->failures[0]->message)
                ->because('the disposal error MUST remain as secondary evidence')
                ->toBe('Harness service disposal caused an error: harness service disposal broke');
        } else {
            Expect::that($result->error?->message)
                ->because('a passing test MUST become unsuccessful')
                ->toBe('harness service disposal broke');
        }
    }

    /**
     * @return iterable<string, array{non-empty-string, Outcome}>
     */
    public static function testScopeOutcomes(): iterable
    {
        yield 'pass' => ['passesBeforeDisposal', Outcome::Errored];
        yield 'existing error' => ['errorsBeforeDisposal', Outcome::Errored];
    }

    #[Test]
    public function normalClassCloseAssignsDisposalToTheLastTest(): void
    {
        [, $sink] = $this->run(Scope::PerClass, ['passesBeforeDisposal', 'errorsBeforeDisposal']);
        $results = $sink->results();

        Expect::that($results[0]->outcome)
            ->because('class disposal MUST NOT change an earlier test')
            ->toBe(Outcome::Passed);
        Expect::that($results[1]->error?->message)
            ->because('the last test error MUST remain primary')
            ->toBe('test broke first');
        Expect::that($results[1]->failures[0]->message)
            ->because('class disposal MUST remain as secondary evidence')
            ->toContain('harness service disposal broke');
    }

    #[Test]
    public function bailRetainsClassDisposalAfterThePrimaryFailure(): void
    {
        [, $sink] = $this->run(
            Scope::PerClass,
            ['errorsBeforeDisposal', 'passesBeforeDisposal'],
            stopAfterFailures: 1,
        );
        $results = $sink->results();

        Expect::that($results)->because('bail MUST stop after the first test')->toHaveCount(1);
        Expect::that($results[0]->error?->message)
            ->because('bail MUST keep the test error primary')
            ->toBe('test broke first');
        Expect::that($results[0]->failures[0]->message)
            ->because('bail MUST retain class disposal evidence')
            ->toContain('harness service disposal broke');
    }

    #[Test]
    public function drainClosesTheClassAndErrorsAPassingTest(): void
    {
        [$threw, $sink, $outcome] = $this->run(
            Scope::PerClass,
            ['passesBeforeDisposal', 'errorsBeforeDisposal'],
            drainRequested: static fn(): bool => true,
        );
        $result = $sink->results()[0];

        Expect::that($threw)->toBeNull();
        Expect::that($result->outcome)
            ->because('an early class close MUST make a passing test unsuccessful')
            ->toBe(Outcome::Errored);
        Expect::that($result->error?->message)->toBe('harness service disposal broke');
        Expect::that($outcome?->remaining)->toHaveCount(1);
        Expect::that($outcome?->drained)->toBeTrue();
    }

    /** @param non-empty-string $method */
    #[Test]
    #[DataSet('workerScopeOutcomes')]
    public function workerDisposalFailsTheRunAndKeepsTheTestResult(string $method, string $primary): void
    {
        [$threw, $sink] = $this->run(Scope::PerWorker, [$method]);
        $result = $sink->results()[0];

        Expect::that($threw)
            ->because('worker disposal MUST make the run unsuccessful')
            ->toBeInstanceOf(WorkerError::class);
        Expect::that($threw->getMessage())
            ->toContain('Worker harness service disposal failed.')
            ->toContain('harness service disposal broke');
        $reportedPrimary = $result->error instanceof ThrowableDetail
            ? $result->error->message
            : $result->outcome->value;
        Expect::that($reportedPrimary)
            ->because('worker disposal MUST NOT replace the completed test result')
            ->toBe($primary);
    }

    /**
     * @return iterable<string, array{non-empty-string, non-empty-string}>
     */
    public static function workerScopeOutcomes(): iterable
    {
        yield 'pass' => ['passesBeforeDisposal', Outcome::Passed->value];
        yield 'existing error' => ['errorsBeforeDisposal', 'test broke first'];
    }

    /**
     * @param non-empty-list<non-empty-string> $methods
     * @param \Closure(): bool|null $drainRequested
     *
     * @return array{?\Throwable, CollectingEventSink, ?WorkerRunOutcome}
     */
    private function run(
        Scope $scope,
        array $methods,
        ?int $stopAfterFailures = null,
        ?\Closure $drainRequested = null,
    ): array {
        $entries = [];

        foreach ($methods as $method) {
            $entries[] = PlanEntryFixture::create(HarnessDisposalMatrixTest::class, $method);
        }

        $registry = new HarnessRegistry([
            new ServiceDefinition(
                FailingHarnessService::class,
                $scope,
                static fn(): FailingHarnessService => new FailingHarnessService(),
            ),
        ]);
        $sink = new CollectingEventSink();
        $threw = null;
        $outcome = null;

        try {
            $outcome = new Worker($registry)->run(
                new ExecutionPlan($entries),
                $sink,
                $stopAfterFailures,
                $drainRequested,
            );
        } catch (\Throwable $failure) {
            $threw = $failure;
        }

        return [$threw, $sink, $outcome];
    }
}

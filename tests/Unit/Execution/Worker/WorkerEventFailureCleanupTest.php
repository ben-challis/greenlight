<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\Worker;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Event\Event;
use Greenlight\Event\EventSink;
use Greenlight\Event\TestFinished;
use Greenlight\Execution\Worker\HarnessServiceDisposal;
use Greenlight\Execution\Worker\Worker;
use Greenlight\Execution\Worker\WorkerError;
use Greenlight\Expect\Expect;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;
use Greenlight\Tests\Fixture\HarnessDisposalMatrix\FailingHarnessService;
use Greenlight\Tests\Fixture\Lifecycle\Services\ServiceProbe;
use Greenlight\Tests\Fixture\Lifecycle\TraceLog;
use Greenlight\Tests\Support\FixturePath;

final readonly class WorkerEventFailureCleanupTest
{
    #[Test]
    #[DataSet('scopeOwners')]
    public function eventFailureClosesAcquiredServices(Scope $scope, bool $externalOwner): void
    {
        TraceLog::drain();
        ServiceProbe::reset();
        $definitions = [new ServiceDefinition(ServiceProbe::class, $scope, static fn(): ServiceProbe => new ServiceProbe())];
        $plan = new TestDiscoverer()->discover([FixturePath::get('Lifecycle/Services')]);
        $failure = new \RuntimeException('Event delivery failed.');
        $sink = $this->failingSink($failure);
        $worker = new Worker($definitions);
        $caught = null;

        try {
            if ($externalOwner) {
                $scopes = new HarnessScopes($definitions);
                HarnessServiceDisposal::runAndClose($scopes, static fn() => $worker->run($plan, $sink, scopes: $scopes));
            } else {
                $worker->run($plan, $sink);
            }
        } catch (\Throwable $error) {
            $caught = $error;
        }

        Expect::that($caught)->toBe($failure);
        Expect::that(TraceLog::drain())->toBe(['probe1:created', 'probe1:touched', 'probe1:disposed']);
    }

    #[Test]
    public function disposalFailurePreservesTheEventFailure(): void
    {
        $failure = new \RuntimeException('Event delivery failed.');
        $plan = new TestDiscoverer()->discover([FixturePath::get('HarnessDisposalMatrix')]);
        $worker = new Worker([
            new ServiceDefinition(FailingHarnessService::class, Scope::PerClass, static fn(): FailingHarnessService => new FailingHarnessService()),
        ]);

        Expect::that(fn() => $worker->run($plan, $this->failingSink($failure)))->toThrow(
            static function (WorkerError $error) use ($failure): void {
                Expect::that($error->getPrevious())->toBe($failure);
                Expect::that($error->getMessage())
                    ->toContain('Event delivery failed.')
                    ->toContain('harness service disposal broke');
            },
        );
    }

    /** @return iterable<string, array{Scope, bool}> */
    public static function scopeOwners(): iterable
    {
        yield 'owned class services' => [Scope::PerClass, false];
        yield 'owned worker services' => [Scope::PerWorker, false];
        yield 'external class services' => [Scope::PerClass, true];
        yield 'external worker services' => [Scope::PerWorker, true];
    }

    private function failingSink(\Throwable $failure): EventSink
    {
        return new readonly class ($failure) implements EventSink {
            public function __construct(private \Throwable $failure) {}

            public function emit(Event $event): void
            {
                if ($event instanceof TestFinished) {
                    throw $this->failure;
                }
            }
        };
    }
}

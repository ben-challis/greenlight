<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Disposable;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;

final class HarnessScopesCloseWorkerTest
{
    #[Test]
    public function closeWorkerCollectsDisposalFailures(): void
    {
        $workerService = new class implements Disposable, Fake {
            #[\Override]
            public function dispose(): never
            {
                throw new \RuntimeException('worker disposal failed');
            }
        };
        $scopes = new HarnessScopes([
            new ServiceDefinition(
                $workerService::class,
                Scope::PerWorker,
                static fn() => $workerService,
            ),
        ]);

        $scopes->resolve($workerService::class, 'test');
        $failures = $scopes->closeWorker();

        Expect::that(\array_map(
            static fn(\Throwable $failure): string => $failure->getMessage(),
            $failures,
        ))
            ->because('worker cleanup MUST retain a disposal failure')
            ->toBe(['worker disposal failed']);
        Expect::that($scopes->closeWorker())
            ->because('failed cleanup MUST still empty the worker scope')
            ->toBe([]);
    }
}

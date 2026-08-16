<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Harness;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Harness\Disposable;
use Greenlight\Harness\HarnessRegistry;
use Greenlight\Harness\HarnessScopes;
use Greenlight\Harness\Scope;
use Greenlight\Harness\ServiceDefinition;

final class HarnessScopesCloseRunTest
{
    #[Test]
    public function closeRunCollectsFailuresFromBothLongLivedScopes(): void
    {
        $suiteService = new class implements Disposable, Fake {
            #[\Override]
            public function dispose(): never
            {
                throw new \RuntimeException('suite disposal failed');
            }
        };
        $runService = new class implements Disposable, Fake {
            #[\Override]
            public function dispose(): never
            {
                throw new \RuntimeException('run disposal failed');
            }
        };
        $scopes = new HarnessScopes(new HarnessRegistry([
            new ServiceDefinition(
                $suiteService::class,
                Scope::PerSuite,
                static fn() => $suiteService,
            ),
            new ServiceDefinition(
                $runService::class,
                Scope::PerRun,
                static fn() => $runService,
            ),
        ]));

        $scopes->resolve($suiteService::class, 'test');
        $scopes->resolve($runService::class, 'test');
        $failures = $scopes->closeRun();

        Expect::that(\array_map(
            static fn(\Throwable $failure): string => $failure->getMessage(),
            $failures,
        ))
            ->because('suite cleanup MUST finish before run cleanup and retain both failures')
            ->toBe(['suite disposal failed', 'run disposal failed']);
        Expect::that($scopes->closeRun())
            ->because('failed cleanup MUST still empty both long-lived scopes')
            ->toBe([]);
    }
}

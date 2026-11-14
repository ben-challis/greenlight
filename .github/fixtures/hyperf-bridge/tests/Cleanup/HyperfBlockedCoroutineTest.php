<?php

declare(strict_types=1);

namespace HyperfBridgeAcceptance\Cleanup;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\ExpectationRuntime;
use Greenlight\Harness\ServiceResolutionFailed;
use Greenlight\Hyperf\ContainerLifetime;
use Greenlight\Hyperf\HyperfPlugin;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Test\TestChannel;
use Swoole\Coroutine\Channel;

use function Greenlight\expect;

final readonly class HyperfBlockedCoroutineTest
{
    #[Test]
    #[DataSet('lifetimes')]
    public function aBlockedCoroutineFailsAtTheDeadlineAndTheNextAttemptRuns(ContainerLifetime $lifetime): void
    {
        $resets = 0;
        $plugin = new HyperfPlugin(
            \dirname(__DIR__, 2),
            containerLifetime: $lifetime,
            reset: static function () use (&$resets): void {
                ++$resets;
            },
        );
        $plugin->onWorkerBootstrap(new WorkerBootstrapContext('blocked-probe', new TestChannel(1), IntegrationResources::empty()));
        $outerDeadline = ExpectationRuntime::deadline();
        $blocked = null;

        try {
            $next = $plugin->runWorker(static function () use ($plugin, $outerDeadline, &$blocked): mixed {
                try {
                    $plugin->runTestAttempt(static function (): mixed {
                        ExpectationRuntime::enterAttempt(\hrtime(true) / 1_000_000_000 + 0.2);

                        return new Channel(1)->pop();
                    });
                } catch (ServiceResolutionFailed $failure) {
                    $blocked = $failure;
                }

                ExpectationRuntime::enterAttempt($outerDeadline);

                return $plugin->runTestAttempt(static fn(): string => 'next');
            });
        } finally {
            ExpectationRuntime::enterAttempt($outerDeadline);
        }

        expect($blocked?->getMessage() ?? '')->toMatch('/did not finish before the test time limit/');
        expect($next)->toBe('next');
        expect($resets)->toBe(2);
    }

    /** @return iterable<string, array{ContainerLifetime}> */
    public static function lifetimes(): iterable
    {
        yield 'worker' => [ContainerLifetime::Worker];
        yield 'attempt' => [ContainerLifetime::TestAttempt];
    }
}

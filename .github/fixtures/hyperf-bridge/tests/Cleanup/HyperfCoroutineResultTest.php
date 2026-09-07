<?php

declare(strict_types=1);

namespace HyperfBridgeAcceptance\Cleanup;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Hyperf\ContainerLifetime;
use Greenlight\Hyperf\HyperfPlugin;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Test\TestChannel;
use Hyperf\Coroutine\Coroutine;

final readonly class HyperfCoroutineResultTest
{
    #[Test]
    #[DataSet('lifetimes')]
    public function returnsNullFromACompletedCoroutine(ContainerLifetime $lifetime): void
    {
        $plugin = $this->plugin($lifetime);

        $result = $plugin->runWorker(static fn(): mixed => $plugin->runTestAttempt(static fn(): null => null));

        Expect::that($result)->toBeNull();
    }

    #[Test]
    #[DataSet('lifetimes')]
    public function returnsFalseFromACompletedCoroutine(ContainerLifetime $lifetime): void
    {
        $plugin = $this->plugin($lifetime);

        $result = $plugin->runWorker(static fn(): mixed => $plugin->runTestAttempt(static fn(): false => false));

        Expect::that($result)->toBeFalse();
    }

    #[Test]
    #[DataSet('lifetimes')]
    public function preservesTheCoroutineFailure(ContainerLifetime $lifetime): void
    {
        $plugin = $this->plugin($lifetime);
        $failure = new \RuntimeException('The coroutine failed.');
        $caught = null;

        try {
            $plugin->runWorker(static fn(): mixed => $plugin->runTestAttempt(static fn(): never => throw $failure));
        } catch (\Throwable $threw) {
            $caught = $threw;
        }

        Expect::that($caught)->toBe($failure);
    }

    #[Test]
    #[DataSet('lifetimes')]
    public function aCoroutineFailureStillDisposesInsideTheRuntime(ContainerLifetime $lifetime): void
    {
        $failure = new \RuntimeException('The coroutine failed.');
        $disposedInCoroutine = false;
        $plugin = new HyperfPlugin(
            \dirname(__DIR__, 2),
            containerLifetime: $lifetime,
            dispose: static function () use (&$disposedInCoroutine): never {
                $disposedInCoroutine = Coroutine::inCoroutine();

                throw new \RuntimeException('The disposal failed.');
            },
        );
        $plugin->onWorkerBootstrap(new WorkerBootstrapContext('cleanup-result-probe', new TestChannel(1), IntegrationResources::empty()));
        $caught = null;

        try {
            $plugin->runWorker(static fn(): mixed => $plugin->runTestAttempt(static fn(): never => throw $failure));
        } catch (\Throwable $threw) {
            $caught = $threw;
        }

        Expect::that($disposedInCoroutine)->toBeTrue();
        Expect::that($caught)->toBe($failure);
    }

    /** @return iterable<string, array{ContainerLifetime}> */
    public static function lifetimes(): iterable
    {
        yield 'worker' => [ContainerLifetime::Worker];
        yield 'attempt' => [ContainerLifetime::TestAttempt];
    }

    private function plugin(ContainerLifetime $lifetime): HyperfPlugin
    {
        $plugin = new HyperfPlugin(\dirname(__DIR__, 2), containerLifetime: $lifetime);
        $plugin->onWorkerBootstrap(new WorkerBootstrapContext('result-probe', new TestChannel(1), IntegrationResources::empty()));

        return $plugin;
    }
}

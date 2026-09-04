<?php

declare(strict_types=1);

namespace HyperfBridgeAcceptance\Cleanup;

use App\Greeter;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Hyperf\ContainerLifetime;
use Greenlight\Hyperf\HyperfPlugin;
use Greenlight\IntegrationFixture\IntegrationResources;
use Greenlight\Plugin\WorkerBootstrapContext;
use Greenlight\Test\TestChannel;
use Hyperf\Context\ApplicationContext;
use Hyperf\Coroutine\Coroutine;
use Psr\Container\ContainerInterface;

final readonly class HyperfCleanupFailureTest
{
    #[Test]
    #[DataSet('failures')]
    public function resetFailureStillDisposesTheContainer(bool $attemptFails, bool $disposeFails): void
    {
        $resetFailure = new \RuntimeException('The reset failed.');
        $attemptFailure = new \RuntimeException('The attempt failed.');
        $resetContainer = null;
        $disposedContainer = null;
        $disposedInCoroutine = false;
        $plugin = new HyperfPlugin(
            \dirname(__DIR__, 2),
            containerLifetime: ContainerLifetime::TestAttempt,
            reset: static function (ContainerInterface $container) use (&$resetContainer, $resetFailure): void {
                $resetContainer = $container;

                throw $resetFailure;
            },
            dispose: static function (ContainerInterface $container) use (&$disposedContainer, &$disposedInCoroutine, $disposeFails): void {
                $disposedContainer = $container;
                $disposedInCoroutine = Coroutine::inCoroutine();

                if ($disposeFails) {
                    throw new \RuntimeException('The disposal failed.');
                }
            },
        );
        $plugin->onWorkerBootstrap(new WorkerBootstrapContext('cleanup-probe', new TestChannel(1), IntegrationResources::empty()));
        $caught = null;

        try {
            $plugin->runTestAttempt(static function () use ($attemptFails, $attemptFailure): void {
                if ($attemptFails) {
                    throw $attemptFailure;
                }
            });
        } catch (\Throwable $failure) {
            $caught = $failure;
        }

        Expect::that($disposedContainer)->toBeInstanceOf(ContainerInterface::class);
        Expect::that($disposedContainer)->toBe($resetContainer);
        Expect::that($disposedInCoroutine)->toBeTrue();
        Expect::that($caught)->toBe($attemptFails ? $attemptFailure : $resetFailure);
        Expect::that(ApplicationContext::getContainer()->has(Greeter::class))->toBeFalse();
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function failures(): iterable
    {
        yield 'reset failure' => [false, false];
        yield 'reset and disposal failures' => [false, true];
        yield 'attempt and cleanup failures' => [true, true];
    }
}

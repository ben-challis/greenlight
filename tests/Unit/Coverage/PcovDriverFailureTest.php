<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\Driver\PcovDriver;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Coverage\FailingPcovDriverRuntime;

final class PcovDriverFailureTest
{
    #[Test]
    public function startFailureLeavesTheCollectionWindowClosed(): void
    {
        $runtime = new FailingPcovDriverRuntime();
        $failure = new \RuntimeException('PCOV start failed.');
        $runtime->startFailure = $failure;
        $driver = new PcovDriver($runtime);

        Expect::that(static function () use ($driver): void {
            $driver->start();
        })
            ->because('a PCOV start failure MUST remain the reported failure')
            ->toThrow(
                static function (\RuntimeException $caught) use ($failure): void {
                    Expect::that($caught)->toBe($failure);
                },
            );
        Expect::that($runtime->calls)
            ->because('a PCOV start failure MUST stop before collection begins')
            ->toBe(['start']);

        Expect::that(static fn(): mixed => $driver->stop())
            ->because('a PCOV start failure MUST leave its collection window closed')
            ->toThrow(
                \LogicException::class,
                message: 'The pcov collection window is not open. Call start() before stop().',
            );
    }

    #[Test]
    public function collectionFailureStopsAndClearsTheRuntimeAndClosesTheWindow(): void
    {
        $runtime = new FailingPcovDriverRuntime();
        $failure = new \RuntimeException('PCOV collection failed.');
        $runtime->collectFailure = $failure;
        $driver = new PcovDriver($runtime);
        $driver->start();

        Expect::that(static fn(): mixed => $driver->stop())
            ->because('a PCOV collection failure MUST remain the reported failure')
            ->toThrow(
                static function (\RuntimeException $caught) use ($failure): void {
                    Expect::that($caught)->toBe($failure);
                },
            );
        Expect::that($runtime->calls)
            ->because('a PCOV collection failure MUST still stop and clear extension state')
            ->toBe(['start', 'collect', 'stop', 'clear']);

        Expect::that(static fn(): mixed => $driver->stop())
            ->because('a failed PCOV collection MUST close its collection window')
            ->toThrow(
                \LogicException::class,
                message: 'The pcov collection window is not open. Call start() before stop().',
            );
    }

    /**
     * @param 'stop'|'clear' $operation
     */
    #[Test]
    #[DataSet('cleanupFailures')]
    public function cleanupFailuresStillClearStateAndCloseTheWindow(
        string $operation,
        string $message,
    ): void {
        $runtime = new FailingPcovDriverRuntime();
        $failure = new \RuntimeException($message);

        if ($operation === 'stop') {
            $runtime->stopFailure = $failure;
        } else {
            $runtime->clearFailure = $failure;
        }

        $driver = new PcovDriver($runtime);
        $driver->start();

        Expect::that(static fn(): mixed => $driver->stop())
            ->because('a PCOV cleanup failure MUST remain the reported failure')
            ->toThrow(
                static function (\RuntimeException $caught) use ($failure): void {
                    Expect::that($caught)->toBe($failure);
                },
            );
        Expect::that($runtime->calls)
            ->because('PCOV cleanup MUST attempt clear even if stop fails')
            ->toBe(['start', 'collect', 'stop', 'clear']);

        Expect::that(static fn(): mixed => $driver->stop())
            ->because('a PCOV cleanup failure MUST close its collection window')
            ->toThrow(
                \LogicException::class,
                message: 'The pcov collection window is not open. Call start() before stop().',
            );
    }

    /**
     * @return iterable<string, array{'stop'|'clear', non-empty-string}>
     */
    public static function cleanupFailures(): iterable
    {
        yield 'stop failure' => ['stop', 'PCOV stop failed.'];

        yield 'clear failure' => ['clear', 'PCOV clear failed.'];
    }
}

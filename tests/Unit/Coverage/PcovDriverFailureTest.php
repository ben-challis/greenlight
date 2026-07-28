<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\Driver\PcovDriver;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Tests\Fixture\Coverage\FailingPcovRuntime;

final class PcovDriverFailureTest
{
    #[Test]
    #[Isolated]
    public function collectionFailureStopsAndClearsTheRuntimeAndClosesTheWindow(): void
    {
        $this->installFakePcovFunctions();
        $driver = new \ReflectionClass(PcovDriver::class)->newInstanceWithoutConstructor();

        FailingPcovRuntime::reset();
        FailingPcovRuntime::$collectFailure = new \RuntimeException('PCOV collection failed.');
        $driver->start();

        Expect::that(static fn(): mixed => $driver->stop())
            ->because('a PCOV collection failure MUST remain the reported failure')
            ->toThrow(
                \RuntimeException::class,
                message: 'PCOV collection failed.',
            )
            ->and(FailingPcovRuntime::$calls)
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
    #[Isolated]
    #[DataSet('cleanupFailures')]
    public function cleanupFailuresStillClearStateAndCloseTheWindow(
        string $operation,
        string $message,
    ): void {
        $this->installFakePcovFunctions();
        $driver = new \ReflectionClass(PcovDriver::class)->newInstanceWithoutConstructor();

        FailingPcovRuntime::reset();

        if ($operation === 'stop') {
            FailingPcovRuntime::$stopFailure = new \RuntimeException($message);
        } else {
            FailingPcovRuntime::$clearFailure = new \RuntimeException($message);
        }

        $driver->start();

        Expect::that(static fn(): mixed => $driver->stop())
            ->because('a PCOV cleanup failure MUST remain the reported failure')
            ->toThrow(
                \RuntimeException::class,
                message: $message,
            )
            ->and(FailingPcovRuntime::$calls)
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

    private function installFakePcovFunctions(): void
    {
        if (\function_exists('pcov\start')) {
            Fail::because('Expected an isolated worker without loaded PCOV functions.');
        }

        eval(<<<'PHP'
            namespace pcov;

            function start(): void
            {
                \Greenlight\Tests\Fixture\Coverage\FailingPcovRuntime::start();
            }

            function collect(): array
            {
                return \Greenlight\Tests\Fixture\Coverage\FailingPcovRuntime::collect();
            }

            function stop(): void
            {
                \Greenlight\Tests\Fixture\Coverage\FailingPcovRuntime::stop();
            }

            function clear(): void
            {
                \Greenlight\Tests\Fixture\Coverage\FailingPcovRuntime::clear();
            }
            PHP);
    }
}

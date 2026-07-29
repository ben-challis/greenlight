<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\Driver\XdebugDriver;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Fixture\Coverage\FailingXdebugRuntime;

final readonly class XdebugDriverFailureTest
{
    /**
     * @param 'collect'|'stop' $operation
     */
    #[Test]
    #[Isolated]
    #[DataSet('runtimeFailures')]
    public function runtimeFailuresStopAndCloseTheCollectionWindow(
        string $operation,
        string $message,
    ): void {
        if (!\defined('XDEBUG_CC_UNUSED')) {
            \define('XDEBUG_CC_UNUSED', 1);
        }

        if (!\defined('XDEBUG_CC_DEAD_CODE')) {
            \define('XDEBUG_CC_DEAD_CODE', 2);
        }

        $runtime = new FailingXdebugRuntime();

        if ($operation === 'collect') {
            $runtime->collectFailure = new \RuntimeException($message);
        } else {
            $runtime->stopFailure = new \RuntimeException($message);
        }

        $driver = new XdebugDriver($runtime);
        $driver->start();

        Expect::that(static fn(): mixed => $driver->stop())
            ->because('an Xdebug runtime failure MUST remain the reported failure')
            ->toThrow(
                \RuntimeException::class,
                message: $message,
            )
            ->and($runtime->calls)
            ->because('Xdebug collection MUST stop the runtime after every collection attempt')
            ->toBe(['start', 'collect', 'stop']);

        Expect::that(static fn(): mixed => $driver->stop())
            ->because('an Xdebug runtime failure MUST close the collection window')
            ->toThrow(
                \LogicException::class,
                message: 'The Xdebug collection window is not open. Call start() before stop().',
            );
    }

    /**
     * @return iterable<string, array{'collect'|'stop', non-empty-string}>
     */
    public static function runtimeFailures(): iterable
    {
        yield 'collection failure' => ['collect', 'Xdebug collection failed.'];

        yield 'stop failure' => ['stop', 'Xdebug stop failed.'];
    }
}

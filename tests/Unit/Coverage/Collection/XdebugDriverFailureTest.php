<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Collection;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\Collection\Driver\XdebugDriver;
use Greenlight\Tests\Fixture\Coverage\FailingXdebugRuntime;

use function Greenlight\expect;

final readonly class XdebugDriverFailureTest
{
    /**
     * @param 'collect'|'stop' $operation
     */
    #[Test]
    #[DataSet('runtimeFailures')]
    public function runtimeFailuresStopAndCloseTheCollectionWindow(
        string $operation,
        string $message,
    ): void {
        $runtime = new FailingXdebugRuntime();
        $failure = new \RuntimeException($message);

        if ($operation === 'collect') {
            $runtime->collectFailure = $failure;
        } else {
            $runtime->stopFailure = $failure;
        }

        $driver = new XdebugDriver($runtime, flags: 3);
        $driver->start();

        expect()->calling(static fn(): mixed => $driver->stop())
            ->because('an Xdebug runtime failure MUST remain the reported failure')
            ->toThrow($failure);

        expect($runtime->calls)
            ->because('Xdebug collection MUST stop the runtime after every collection attempt')
            ->toBe(['start', 'collect', 'stop']);

        expect()->calling(static fn(): mixed => $driver->stop())
            ->because('an Xdebug runtime failure MUST close the collection window')
            ->toThrow(
                \LogicException::class,
                message: 'The Xdebug collection window is not open. Call start() before stop().',
            );
    }

    #[Test]
    public function aStopFailureRetainsTheCollectionFailureAsItsCause(): void
    {
        $runtime = new FailingXdebugRuntime();
        $collectionFailure = new \RuntimeException('Xdebug collection failed.');
        $stopFailure = new \RuntimeException('Xdebug stop failed.');
        $runtime->collectFailure = $collectionFailure;
        $runtime->stopFailure = $stopFailure;
        $driver = new XdebugDriver($runtime, flags: 3);
        $driver->start();

        expect()->calling(static fn(): mixed => $driver->stop())
            ->because('an Xdebug stop failure MUST retain the collection failure as its cause')
            ->toThrow(static function (\RuntimeException $caught) use ($collectionFailure, $stopFailure): void {
                expect($caught)->toBe($stopFailure);
                expect($caught->getPrevious())->toBe($collectionFailure);
            });

        expect($runtime->calls)
            ->because('Xdebug collection MUST still stop the runtime after collection fails')
            ->toBe(['start', 'collect', 'stop']);

        expect()->calling(static fn(): mixed => $driver->stop())
            ->because('combined Xdebug runtime failures MUST close the collection window')
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

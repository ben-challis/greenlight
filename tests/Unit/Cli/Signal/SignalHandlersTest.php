<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Signal;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Signal\SignalHandlers;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Process\GracefulShutdown;
use Greenlight\Tests\Fixture\Cli\Signal\RecordingSignalOperations;

final class SignalHandlersTest
{
    #[Test]
    #[DataSet('supportedSignals')]
    public function eachSupportedSignalGetsAGracefulFirstHandler(int $index, int $signal): void
    {
        $operations = new RecordingSignalOperations(available: true);
        $shutdown = new GracefulShutdown();

        SignalHandlers::install($shutdown, $operations);

        $handler = $operations->registrations[$index]['handler'] ?? null;

        Expect::value($handler)
            ->because('Each supported signal MUST get a callable first handler.')
            ->toBeCallable();

        $handler($signal);

        Expect::value($shutdown->requested())
            ->because('each supported signal MUST request graceful shutdown on first delivery')
            ->toBeTrue();
        Expect::value($shutdown->signal())->toBe($signal);
    }

    #[Test]
    public function unavailableSignalOperationsAreAStrictNoOp(): void
    {
        $operations = new RecordingSignalOperations(available: false);
        $shutdown = new GracefulShutdown();

        SignalHandlers::install($shutdown, $operations);

        Expect::value($operations->asyncEnabled)
            ->because('unavailable signal operations do not enable asynchronous signals')
            ->toBeFalse();
        Expect::value($operations->registrations)
            ->because('unavailable signal operations do not register handlers')
            ->toBe([]);
        Expect::value($shutdown->requested())->toBeFalse();
    }

    #[Test]
    public function theFirstSignalRequestsShutdownAndRestoresDefaultHandlers(): void
    {
        $operations = new RecordingSignalOperations(available: true);
        $shutdown = new GracefulShutdown();

        SignalHandlers::install($shutdown, $operations);

        Expect::value($operations->asyncEnabled)
            ->because('available signal operations enable asynchronous signals')
            ->toBeTrue();
        Expect::value(\array_column($operations->registrations, 'signal'))
            ->because('SIGINT and SIGTERM handlers are registered')
            ->toBe([\SIGINT, \SIGTERM]);

        $handler = $operations->registrations[0]['handler'] ?? null;

        Expect::value($handler)
            ->because('SignalHandlers MUST register a callable for SIGINT.')
            ->toBeCallable();

        $handler(\SIGTERM);

        Expect::value($shutdown->requested())
            ->because('the first signal requests graceful shutdown')
            ->toBeTrue();
        Expect::value($shutdown->signal())->toBe(\SIGTERM);
        Expect::value($operations->registrations[2] ?? null)
            ->because('the first signal restores the default SIGINT handler')
            ->toBe(['signal' => \SIGINT, 'handler' => \SIG_DFL]);
        Expect::value($operations->registrations[3] ?? null)
            ->because('the first signal restores the default SIGTERM handler')
            ->toBe(['signal' => \SIGTERM, 'handler' => \SIG_DFL]);
    }

    /**
     * @return iterable<string, array{non-negative-int, positive-int}>
     */
    public static function supportedSignals(): iterable
    {
        yield 'SIGINT' => [0, \SIGINT];

        yield 'SIGTERM' => [1, \SIGTERM];
    }
}

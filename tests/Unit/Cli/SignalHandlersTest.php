<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Cli\SignalHandlers;
use Greenlight\Core\GracefulShutdown;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Tests\Fixture\Cli\RecordingSignalOperations;

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

        if (!\is_callable($handler)) {
            Fail::because('Expected each supported signal to get a callable first handler.');
        }

        $handler($signal);

        Expect::that($shutdown->requested())
            ->because('each supported signal MUST request graceful shutdown on first delivery')
            ->toBeTrue()
            ->and($shutdown->exitCode())
            ->toBe(128 + $signal);
    }

    #[Test]
    public function unavailableSignalOperationsAreAStrictNoOp(): void
    {
        $operations = new RecordingSignalOperations(available: false);
        $shutdown = new GracefulShutdown();

        SignalHandlers::install($shutdown, $operations);

        Expect::that($operations->asyncEnabled)
            ->because('unavailable signal operations do not enable asynchronous signals')
            ->toBeFalse()
            ->and($operations->registrations)
            ->because('unavailable signal operations do not register handlers')
            ->toBe([])
            ->and($shutdown->requested())->toBeFalse();
    }

    #[Test]
    public function theFirstSignalRequestsShutdownAndRestoresDefaultHandlers(): void
    {
        $operations = new RecordingSignalOperations(available: true);
        $shutdown = new GracefulShutdown();

        SignalHandlers::install($shutdown, $operations);

        Expect::that($operations->asyncEnabled)
            ->because('available signal operations enable asynchronous signals')
            ->toBeTrue()
            ->and(\array_column($operations->registrations, 'signal'))
            ->because('SIGINT and SIGTERM handlers are registered')
            ->toBe([\SIGINT, \SIGTERM]);

        $handler = $operations->registrations[0]['handler'] ?? null;

        if (!\is_callable($handler)) {
            Fail::because('Expected SignalHandlers to register a callable for SIGINT.');
        }

        $handler(\SIGTERM);

        Expect::that($shutdown->requested())
            ->because('the first signal requests graceful shutdown')
            ->toBeTrue()
            ->and($shutdown->exitCode())->toBe(128 + \SIGTERM)
            ->and($operations->registrations[2] ?? null)
            ->because('the first signal restores the default SIGINT handler')
            ->toBe(['signal' => \SIGINT, 'handler' => \SIG_DFL])
            ->and($operations->registrations[3] ?? null)
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

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\ProcessPool\Orchestrator;

use Greenlight\Cli\ExitCode;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\ProcessPool\Worker\WorkerProcess;

final readonly class LoggedWorkerProcess implements Fake
{
    /**
     * @param non-empty-string $address
     * @param non-empty-string $workerId
     * @param non-empty-string $token
     */
    public static function run(string $address, string $workerId, string $token): int
    {
        self::record('start', $workerId);

        \register_shutdown_function(static function () use ($workerId): void {
            self::record('exit-start', $workerId);
            $delay = \getenv('GREENLIGHT_RETIREMENT_DELAY_MICROSECONDS');

            if (\is_string($delay) && \ctype_digit($delay)) {
                \usleep((int) $delay);
            }

            self::record('exit-end', $workerId);
        });

        return ExitCode::fromCommandResult(new WorkerProcess()->run($address, $workerId, $token))->value();
    }

    private static function record(string $phase, string $workerId): void
    {
        $path = \getenv('GREENLIGHT_RETIREMENT_LOG');

        if (!\is_string($path) || $path === '') {
            return;
        }

        \file_put_contents($path, \json_encode([
            'phase' => $phase,
            'worker' => $workerId,
            'channel' => \getenv('GREENLIGHT_CHANNEL'),
            'at' => \microtime(true),
        ], \JSON_THROW_ON_ERROR) . "\n", \FILE_APPEND | \LOCK_EX);
    }
}

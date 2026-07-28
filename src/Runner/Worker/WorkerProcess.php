<?php

declare(strict_types=1);

namespace Greenlight\Runner\Worker;

use Greenlight\Core\ErrorTrap;
use Greenlight\Runner\Protocol\ProtocolError;
use Greenlight\Runner\Protocol\SocketChannel;

/**
 * The hidden __worker command has no compatibility guarantee.
 *
 * @internal
 */
final readonly class WorkerProcess
{
    private const float RECEIVE_POLL_SECONDS = 30.0;

    public function __construct(
        private float $receivePollSeconds = self::RECEIVE_POLL_SECONDS,
    ) {}

    /**
     * @param non-empty-string $address
     * @param non-empty-string $workerId
     * @param non-empty-string $token
     * @throws ProtocolError
     */
    public function run(string $address, string $workerId, string $token): int
    {
        // The terminal sends Ctrl+C to the complete process group. Workers
        // ignore SIGINT. Thus, the orchestrator can control an orderly drain.
        // Crash containment does not report active tests as crashes from SIGINT.
        $interruptHandler = \function_exists('pcntl_signal_get_handler')
            ? \pcntl_signal_get_handler(\SIGINT)
            : null;

        if (\function_exists('pcntl_signal')) {
            \pcntl_signal(\SIGINT, \SIG_IGN);
        }

        try {
            return $this->runWhileIgnoringInterrupt($address, $workerId, $token);
        } finally {
            if ($interruptHandler !== null && \function_exists('pcntl_signal')) {
                \pcntl_signal(\SIGINT, $interruptHandler);
            }
        }
    }

    /**
     * @param non-empty-string $address
     * @param non-empty-string $workerId
     * @param non-empty-string $token
     * @throws ProtocolError
     */
    private function runWhileIgnoringInterrupt(string $address, string $workerId, string $token): int
    {
        $stream = ErrorTrap::run(static function () use ($address, &$errorCode, &$errorMessage) {
            return \stream_socket_client($address, $errorCode, $errorMessage, 10.0);
        });

        if ($stream === false) {
            \fwrite(\STDERR, \sprintf("The worker did not connect to %s: %s\n", $address, $errorMessage));

            return 1;
        }

        return new WorkerSession($this->receivePollSeconds)->run(
            new SocketChannel($stream),
            $workerId,
            $token,
        );
    }
}

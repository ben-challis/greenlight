<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Protocol;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Execution\ProcessPool\Protocol\ProtocolError;
use Greenlight\Expect\Expect;

final readonly class ProtocolErrorTest
{
    #[Test]
    public function workerStartupFailureIncludesCapturedOutputExactly(): void
    {
        $error = ProtocolError::workerNeverConnected(
            'worker-2',
            0.5,
            "PHP Fatal error: boot failed\n",
        );

        Expect::that($error->getMessage())
            ->because('worker startup failures MUST preserve captured output')
            ->toBe(
                'Worker "worker-2" did not connect within 0.5 seconds. '
                . 'The machine can have insufficient resources to start a worker. '
                . 'Greenlight stopped the run to prevent an unlimited wait.'
                . "\nWorker output:\nPHP Fatal error: boot failed\n",
            );
    }

    /**
     * @param \Closure(): ProtocolError $factory
     */
    #[Test]
    #[DataSet('workerStateErrors')]
    public function workerStateErrorsHaveNamedProtocolErrors(\Closure $factory, string $message): void
    {
        $error = $factory();

        Expect::that($error)
            ->because('invalid worker message order MUST produce a protocol error')
            ->toBeInstanceOf(ProtocolError::class);
        Expect::that($error->getMessage())->toBe($message);
    }

    /**
     * @return iterable<string, array{\Closure(): ProtocolError, string}>
     */
    public static function workerStateErrors(): iterable
    {
        yield 'duplicate bootstrap' => [
            ProtocolError::duplicateBootstrap(...),
            'Worker received bootstrap more than once.',
        ];
        yield 'bootstrap channel mismatch' => [
            ProtocolError::bootstrapChannelMismatch(...),
            'Worker bootstrap channel does not match GREENLIGHT_CHANNEL.',
        ];
        yield 'assignment before bootstrap' => [
            ProtocolError::assignmentBeforeBootstrap(...),
            'Worker received an assignment before bootstrap completed.',
        ];
    }

    /**
     * @param \Closure(string, float, string): ProtocolError $factory
     */
    #[Test]
    #[DataSet('workerTimeoutErrors')]
    public function workerTimeoutErrorsRetainZeroDiagnostics(
        \Closure $factory,
        string $expectedMessage,
    ): void {
        $error = $factory('worker-0', 1.5, '0');

        Expect::that($error->getMessage())
            ->because('worker timeout errors MUST retain non-empty diagnostics')
            ->toBe($expectedMessage . "\nWorker output:\n0");
    }

    /**
     * @return iterable<string, array{
     *     \Closure(string, float, string): ProtocolError,
     *     string,
     * }>
     */
    public static function workerTimeoutErrors(): iterable
    {
        yield 'never connected' => [
            ProtocolError::workerNeverConnected(...),
            'Worker "worker-0" did not connect within 1.5 seconds. '
            . 'The machine can have insufficient resources to start a worker. '
            . 'Greenlight stopped the run to prevent an unlimited wait.',
        ];
        yield 'stalled' => [
            ProtocolError::workerStalled(...),
            'Worker "worker-0" sent no message for 1.5 seconds after connection. '
            . 'No test was active. The worker stopped responding between protocol messages. '
            . 'Greenlight stopped the run to prevent an unlimited wait.',
        ];
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Execution\ProcessPool\Orchestrator\NativeWorkerTransport;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTransportEvent;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTransportEventKind;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Drain;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Fatal;
use Greenlight\Execution\ProcessPool\Protocol\Messages\Hello;
use Greenlight\Tests\Support\PhpSubprocess;

use function Greenlight\expect;

final readonly class NativeWorkerTransportFrameTest
{
    // The worker sends hello, waits for one frame, writes a large frame, and
    // exits.
    private const string WORKER = <<<'PHP'
        [, , $address, $workerId, $token] = $argv;
        $socket = stream_socket_client($address);
        $frame = static fn(array $message): string => pack('N', strlen($json = json_encode($message))) . $json;
        fwrite($socket, $frame(['v' => 1, 't' => 'hello', 'p' => ['workerId' => $workerId, 'token' => $token, 'pid' => getmypid()]]));
        fflush($socket);
        $length = unpack('N', fread($socket, 4))[1];
        fread($socket, $length);

        fwrite($socket, $frame(['v' => 1, 't' => 'fatal', 'p' => ['detail' => [
            'class' => 'RuntimeException',
            'message' => str_repeat('m', 1024 * 1024),
            'file' => '/fixture/worker.php',
            'line' => 1,
            'stackFrames' => [],
        ]]]));

        fflush($socket);
        PHP;

    #[Test]
    #[Timeout(10.0)]
    public function aLargeFinalFrameArrivesBeforeTheDisconnect(): void
    {
        $transport = $this->authenticatedTransport();
        $fatal = null;

        try {
            $this->pollUntil(
                $transport,
                WorkerTransportEventKind::WorkerDisconnected,
                static function (WorkerTransportEvent $event) use (&$fatal): void {
                    if ($event->message instanceof Fatal) {
                        $fatal = $event->message;
                    }
                },
            );

            expect($fatal)
                ->because('the transport MUST deliver the final frame of an exited worker before its disconnect')
                ->toBeInstanceOf(Fatal::class);
            expect(\strlen($fatal->detail->message))->toBe(1024 * 1024);
        } finally {
            $transport->close();
        }
    }

    private function authenticatedTransport(): NativeWorkerTransport
    {
        $transport = NativeWorkerTransport::listen(
            PhpSubprocess::command(['-r', self::WORKER]),
            \dirname(__DIR__, 5),
        );
        $transport->start('w-1', 1);

        return $transport;
    }

    /**
     * Polls until the event kind arrives. Resolves a valid hello and sends
     * one drain frame to the worker.
     *
     * @param (\Closure(WorkerTransportEvent): void)|null $observe
     * @return list<WorkerTransportEventKind>
     */
    private function pollUntil(
        NativeWorkerTransport $transport,
        WorkerTransportEventKind $until,
        ?\Closure $observe = null,
    ): array {
        $kinds = [];
        $deadline = \hrtime(true) + 8_000_000_000;

        while (\hrtime(true) < $deadline) {
            foreach ($transport->poll() as $event) {
                $kinds[] = $event->kind;

                if ($observe instanceof \Closure) {
                    $observe($event);
                }

                if ($event->connectionId !== null && $event->message instanceof Hello) {
                    $transport->resolveConnection($event->connectionId, $event->message->workerId);
                    $transport->send($event->message->workerId, new Drain());
                }

                if ($event->kind === $until) {
                    return \array_values(\array_filter(
                        $kinds,
                        static fn(WorkerTransportEventKind $kind): bool => $kind !== WorkerTransportEventKind::ConnectionMessage
                            && $kind !== WorkerTransportEventKind::WorkerMessage,
                    ));
                }
            }
        }

        throw new \LogicException(\sprintf('The transport did not report %s.', $until->name));
    }
}

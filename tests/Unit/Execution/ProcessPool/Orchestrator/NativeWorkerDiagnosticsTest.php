<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Execution\ProcessPool\Orchestrator;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use Greenlight\Execution\ProcessPool\Orchestrator\NativeWorkerTransport;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTransportEventKind;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\PhpSubprocess;

final readonly class NativeWorkerDiagnosticsTest
{
    #[Test]
    #[Timeout(10.0)]
    public function binaryOutputDoesNotDelayAnotherWorkersDisconnection(): void
    {
        $transport = NativeWorkerTransport::listen(PhpSubprocess::command(['-r', <<<'PHP'
        if ($_SERVER['argv'][3] === 'quiet') {
            fwrite(STDERR, 'quiet ready');
            exit(0);
        }

        stream_set_blocking(STDOUT, false);
        fwrite(STDERR, 'noisy ready');
        $chunk = str_repeat("\xFF", 65536);
        $deadline = hrtime(true) + 2_000_000_000;
        $bytes = 0;
        $frame = 0;

        while (hrtime(true) < $deadline && $bytes < 64 * 1024 * 1024) {
            $written = fwrite(STDOUT, substr($chunk, 0, 65536 - $frame));
            if ($written === false) {
                break;
            }
            $bytes += $written;
            $frame += $written;
            if ($frame === 65536) {
                $frame = 0;
                usleep(1000);
            }
        }

        fwrite(STDERR, 'noisy output finished');
        PHP]), \dirname(__DIR__, 5));

        try {
            $transport->start('quiet', 1);
            $this->waitForDiagnostic($transport, 'quiet', 'quiet ready');
            $transport->start('noisy', 2);
            $this->waitForDiagnostic($transport, 'noisy', 'noisy ready');
            \usleep(5_000);
            $deadline = \hrtime(true) + 2_000_000_000;
            $quietDisconnected = false;

            do {
                foreach ($transport->poll() as $event) {
                    if ($event->kind === WorkerTransportEventKind::WorkerDisconnected && $event->workerId === 'quiet') {
                        $quietDisconnected = true;
                    }
                }
            } while (!$quietDisconnected && \hrtime(true) < $deadline);

            Expect::that($quietDisconnected)->toBeTrue();
            Expect::that($transport->diagnostics('noisy'))
                ->because('the quiet worker must disconnect before the noisy writer stops')
                ->not()->toContain('noisy output finished');
        } finally {
            $transport->close();
        }
    }

    #[Test]
    #[DataSet('streams')]
    #[Timeout(30.0)]
    public function largeDiagnosticWritesCompleteAndKeepTheBoundedTail(string $stream): void
    {
        $transport = NativeWorkerTransport::listen(PhpSubprocess::command([
            '-r',
            \sprintf('fwrite(%s, str_repeat("x", 1024 * 1024) . "final diagnostic");', $stream),
        ]), \dirname(__DIR__, 5));

        try {
            $transport->start('diagnostics', 1);
            $disconnected = false;

            while (!$disconnected) {
                foreach ($transport->poll() as $event) {
                    if ($event->kind === WorkerTransportEventKind::WorkerDisconnected) {
                        $disconnected = true;
                    }
                }
            }

            Expect::that($transport->diagnostics('diagnostics'))
                ->toBe(\str_repeat('x', 65_536 - \strlen('final diagnostic')) . 'final diagnostic');
        } finally {
            $transport->close();
        }
    }

    /** @return iterable<string, array{string}> */
    public static function streams(): iterable
    {
        yield 'standard output' => ['STDOUT'];
        yield 'standard error' => ['STDERR'];
    }

    /** @param non-empty-string $workerId */
    private function waitForDiagnostic(NativeWorkerTransport $transport, string $workerId, string $marker): void
    {
        $deadline = \hrtime(true) + 2_000_000_000;

        do {
            $diagnostics = $transport->diagnostics($workerId);

            if (\str_contains($diagnostics, $marker)) {
                break;
            }

            \usleep(1000);
        } while (\hrtime(true) < $deadline);

        Expect::that($diagnostics)->toContain($marker);
    }
}

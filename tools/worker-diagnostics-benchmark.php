<?php

declare(strict_types=1);

namespace Greenlight\Tools;

use Greenlight\Execution\ProcessPool\Orchestrator\NativeWorkerTransport;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTransportEventKind;

require __DIR__ . '/../vendor/autoload.php';

// Measure pipe throughput without worker protocol messages.
foreach (['STDOUT', 'STDERR'] as $stream) {
    for ($sample = 1; $sample <= 3; ++$sample) {
        $transport = NativeWorkerTransport::listen([
            \PHP_BINARY,
            '-n',
            '-r',
            \sprintf('fwrite(%s, str_repeat("x", 1024 * 1024));', $stream),
        ], \dirname(__DIR__));
        $startedAt = \hrtime(true);
        $polls = 0;

        try {
            $transport->start('diagnostics', 1);
            $disconnected = false;

            while (!$disconnected) {
                ++$polls;

                foreach ($transport->poll() as $event) {
                    if ($event->kind === WorkerTransportEventKind::WorkerDisconnected) {
                        $disconnected = true;
                    }
                }
            }

            \printf(
                "%s sample=%d bytes=1048576 seconds=%.6f polls=%d retained=%d\n",
                $stream,
                $sample,
                (\hrtime(true) - $startedAt) / 1_000_000_000,
                $polls,
                \strlen($transport->diagnostics('diagnostics')),
            );
        } finally {
            $transport->close();
        }
    }
}

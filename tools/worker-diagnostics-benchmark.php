<?php

declare(strict_types=1);

namespace Greenlight\Tools;

use Greenlight\Execution\ProcessPool\Orchestrator\NativeWorkerTransport;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerHandle;
use Greenlight\Execution\ProcessPool\Orchestrator\WorkerTransportEventKind;

require __DIR__ . '/../vendor/autoload.php';

// A paced writer stays active long enough to expose an unbounded drain.
$producer = <<<'PHP'
stream_set_blocking(STDOUT, false);
fwrite(STDERR, "ready\n");
$chunk = str_repeat($argv[1] === 'binary' ? "\xFF" : 'x', 65536);
$deadline = hrtime(true) + 300_000_000;
$bytes = 0;
$frame = 0;
while (hrtime(true) < $deadline && $bytes < 16 * 1024 * 1024) {
    $written = fwrite(STDOUT, $chunk);
    if ($written === false) {
        break;
    }
    $bytes += $written;
    $frame += $written;
    if ($frame >= 65536) {
        $frame = 0;
        usleep(1000);
    }
}
PHP;

foreach (['ascii', 'binary'] as $encoding) {
    for ($sample = 1; $sample <= 3; ++$sample) {
        $process = \proc_open(
            [\PHP_BINARY, '-n', '-r', $producer, $encoding],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (!\is_resource($process)) {
            throw new \RuntimeException('The diagnostic benchmark did not start its writer.');
        }

        try {
            \fclose($pipes[0]);
            \stream_set_timeout($pipes[2], 2);

            if (\fgets($pipes[2]) !== "ready\n") {
                throw new \RuntimeException('The diagnostic writer did not become ready.');
            }

            \usleep(5_000);
            $handle = new WorkerHandle('diagnostic-writer', 1, $process, $pipes[1], $pipes[2]);
            $startedAt = \hrtime(true);
            $handle->drainPipes();

            \printf(
                "PACED sample=%d encoding=%s seconds=%.6f writer_running=%s retained=%d\n",
                $sample,
                $encoding,
                (\hrtime(true) - $startedAt) / 1_000_000_000,
                $handle->isRunning() ? 'yes' : 'no',
                \strlen($handle->diagnostics),
            );
        } finally {
            \proc_terminate($process, 9);
            \fclose($pipes[1]);
            \fclose($pipes[2]);
            \proc_close($process);
        }
    }
}

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

// Measure transient memory with a diagnostic burst that exceeds pipe capacity.
foreach ([8, 64] as $mebibytes) {
    for ($sample = 1; $sample <= 3; ++$sample) {
        $stdout = \tmpfile();
        $empty = \fopen('php://memory', 'w+');

        if ($stdout === false || $empty === false) {
            throw new \RuntimeException('The diagnostic benchmark did not open its streams.');
        }

        try {
            $chunk = \str_repeat('x', 1024 * 1024);

            for ($index = 0; $index < $mebibytes; ++$index) {
                if (\fwrite($stdout, $chunk) !== \strlen($chunk)) {
                    throw new \RuntimeException('The diagnostic benchmark did not write its input.');
                }
            }

            \rewind($stdout);
            $handle = new WorkerHandle('diagnostic-burst', 1, $empty, $stdout, $empty);
            \memory_reset_peak_usage();
            $memoryBefore = \memory_get_usage();
            $startedAt = \hrtime(true);
            $handle->drainPipes();
            $seconds = (\hrtime(true) - $startedAt) / 1_000_000_000;
            $additionalPeakBytes = \memory_get_peak_usage() - $memoryBefore;

            if ($handle->diagnostics !== \str_repeat('x', 65_536)) {
                throw new \RuntimeException('The diagnostic benchmark did not retain the expected tail.');
            }

            \printf(
                "BURST sample=%d bytes=%d seconds=%.6f additional_peak_bytes=%d retained=%d\n",
                $sample,
                $mebibytes * 1024 * 1024,
                $seconds,
                $additionalPeakBytes,
                \strlen($handle->diagnostics),
            );
        } finally {
            \fclose($stdout);
            \fclose($empty);
        }
    }
}

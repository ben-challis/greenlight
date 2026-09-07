<?php

declare(strict_types=1);

namespace Greenlight\Tools;

/**
 * Measures output capture for small writes and large writes beyond the default limit.
 * Run on an idle machine with Xdebug disabled. Compare the same command at each revision.
 * Each shape has one warmup and five measured samples. Every sample checks the captured bytes.
 *
 * Usage: XDEBUG_MODE=off php tools/output-capture-benchmark.php
 */

use Greenlight\Execution\Worker\OutputCapture;

require \dirname(__DIR__) . '/vendor/autoload.php';

foreach ([16_384, 65_536, 262_144] as $writes) {
    $samples = [];

    for ($round = 0; $round < 6; ++$round) {
        $capture = new OutputCapture();
        $started = \hrtime(true);
        $capture->start();

        for ($write = 0; $write < $writes; ++$write) {
            echo 'abcd';
        }

        $output = $capture->stop();
        $elapsed = (\hrtime(true) - $started) / 1_000_000_000;

        if ($output->stdout !== \str_repeat('abcd', $writes) || $output->stdoutTruncated) {
            throw new \RuntimeException('Output capture did not preserve the benchmark output.');
        }

        if ($round > 0) {
            $samples[] = $elapsed;
        }
    }

    \sort($samples);
    \printf("%d writes, %d bytes: median %.6f seconds\n", $writes, $writes * 4, $samples[2]);
}

foreach (['ascii' => 'x', 'malformed' => "\xFF"] as $shape => $byte) {
    $payload = \str_repeat($byte, 16 * 1024 * 1024);
    $expected = $shape === 'ascii'
        ? \str_repeat('x', 1_048_576)
        : \str_repeat("\u{FFFD}", 349_525);
    $samples = [];
    $peaks = [];

    for ($round = 0; $round < 6; ++$round) {
        $capture = new OutputCapture();
        \memory_reset_peak_usage();
        $baseline = \memory_get_usage();
        $started = \hrtime(true);
        $capture->start();
        echo $payload;
        $output = $capture->stop();
        $elapsed = (\hrtime(true) - $started) / 1_000_000_000;
        $extraPeakBytes = \memory_get_peak_usage() - $baseline;

        if ($output->stdout !== $expected || !$output->stdoutTruncated) {
            throw new \RuntimeException('Output capture did not preserve the bounded benchmark output.');
        }

        if ($round > 0) {
            $samples[] = $elapsed;
            $peaks[] = $extraPeakBytes;
        }

        unset($capture, $output);
    }

    \sort($samples);
    \sort($peaks);
    \printf("16 MiB %s write: median %.6f seconds, %d extra peak bytes\n", $shape, $samples[2], $peaks[2]);
}

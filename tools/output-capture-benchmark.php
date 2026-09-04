<?php

declare(strict_types=1);

/**
 * Measures output capture for repeated four-byte writes up to the default limit.
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

<?php

declare(strict_types=1);

if (!\posix_setpgid(0, 0)) {
    \fwrite(\STDERR, "The process-group launcher could not create a process group.\n");

    exit(1);
}

$rawArguments = $_SERVER['argv'] ?? null;

if (!\is_array($rawArguments)) {
    \fwrite(\STDERR, "The process-group launcher received invalid arguments.\n");

    exit(1);
}

$arguments = [];

foreach (\array_slice($rawArguments, 1) as $argument) {
    if (!\is_string($argument)) {
        \fwrite(\STDERR, "The process-group launcher received invalid arguments.\n");

        exit(1);
    }

    $arguments[] = $argument;
}

\pcntl_exec(\PHP_BINARY, $arguments);

\fwrite(\STDERR, "The process-group launcher could not start PHP.\n");

exit(1);

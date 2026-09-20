<?php

declare(strict_types=1);

const DEFAULT_MAX_PARALLELISM = 1;
const MAX_PARALLELISM_ENVIRONMENT_VARIABLE = 'GREENLIGHT_LOCAL_CI_MAX_PARALLELISM';
const LOCK_DIRECTORY_ENVIRONMENT_VARIABLE = 'GREENLIGHT_LOCAL_CI_LOCK_DIRECTORY';

/**
 * Limits concurrent local CI commands from Git worktrees.
 * A truthy CI environment variable bypasses the limit.
 *
 * @param list<string> $arguments
 */
function main(array $arguments): int
{
    $command = \command($arguments);

    if (\isTruthyEnvironmentValue(\getenv('CI'))) {
        return \run($command);
    }

    $maxParallelism = \maxParallelism();
    $lockDirectory = \lockDirectory();
    $lock = \acquireLock($lockDirectory, $maxParallelism);

    try {
        return \run($command);
    } finally {
        \flock($lock, \LOCK_UN);
        \fclose($lock);
    }
}

/**
 * @param list<string> $arguments
 *
 * @return non-empty-list<string>
 */
function command(array $arguments): array
{
    \array_shift($arguments);

    if (($arguments[0] ?? null) === '--') {
        \array_shift($arguments);
    }

    if ($arguments === []) {
        \fail('The local CI lock requires a command after "--".');
    }

    if ($arguments[0] === '@php') {
        $arguments[0] = \PHP_BINARY;
    } elseif ($arguments[0] === '@composer') {
        $composerBinary = \getenv('COMPOSER_BINARY');
        $arguments[0] = $composerBinary === false || $composerBinary === '' ? 'composer' : $composerBinary;
    }

    /** @var non-empty-list<string> $arguments */
    return $arguments;
}

/** @return list<string> */
function cliArguments(mixed $rawArguments): array
{
    if (!\is_array($rawArguments)) {
        \fail('The local CI lock could not read the command arguments.');
    }

    $arguments = [];

    foreach ($rawArguments as $argument) {
        if (!\is_string($argument)) {
            \fail('The local CI lock received an invalid command argument.');
        }

        $arguments[] = $argument;
    }

    return $arguments;
}

function isTruthyEnvironmentValue(string|false $value): bool
{
    if ($value === false) {
        return false;
    }

    return !\in_array(\strtolower(\trim($value)), ['', '0', 'false', 'no', 'off'], true);
}

/** @return positive-int */
function maxParallelism(): int
{
    $configured = \getenv(MAX_PARALLELISM_ENVIRONMENT_VARIABLE);

    if ($configured === false || $configured === '') {
        return DEFAULT_MAX_PARALLELISM;
    }

    if (\preg_match('/^[1-9][0-9]*$/D', $configured) !== 1) {
        \fail(\sprintf(
            'The %s value must be a positive integer.',
            MAX_PARALLELISM_ENVIRONMENT_VARIABLE,
        ));
    }

    $maximum = (int) $configured;

    if ($maximum < 1 || $maximum > 64) {
        \fail(\sprintf(
            'The %s value must not be more than 64.',
            MAX_PARALLELISM_ENVIRONMENT_VARIABLE,
        ));
    }

    return $maximum;
}

function lockDirectory(): string
{
    $configured = \getenv(LOCK_DIRECTORY_ENVIRONMENT_VARIABLE);

    if ($configured !== false && $configured !== '') {
        return $configured;
    }

    $repositoryRoot = \dirname(__DIR__);
    $gitPath = $repositoryRoot . '/.git';
    $commonGitDirectory = $gitPath;

    if (\is_file($gitPath)) {
        $gitFile = \file_get_contents($gitPath);

        if ($gitFile === false || \preg_match('/^gitdir: (.+)$/D', \trim($gitFile), $matches) !== 1) {
            \fail('The local CI lock could not read the Git worktree metadata.');
        }

        $worktreeGitDirectory = $matches[1];

        if (!\str_starts_with($worktreeGitDirectory, '/')) {
            $worktreeGitDirectory = $repositoryRoot . '/' . $worktreeGitDirectory;
        }

        $resolvedWorktreeGitDirectory = \realpath($worktreeGitDirectory);

        if ($resolvedWorktreeGitDirectory === false) {
            \fail('The local CI lock could not resolve the Git worktree metadata.');
        }

        $commonGitDirectory = \basename(\dirname($resolvedWorktreeGitDirectory)) === 'worktrees'
            ? \dirname($resolvedWorktreeGitDirectory, 2)
            : $resolvedWorktreeGitDirectory;
    }

    $resolvedCommonGitDirectory = \realpath($commonGitDirectory);

    if ($resolvedCommonGitDirectory === false) {
        \fail('The local CI lock could not resolve the common Git directory.');
    }

    return \sys_get_temp_dir()
        . '/greenlight-local-ci-'
        . \substr(\hash('sha256', $resolvedCommonGitDirectory), 0, 16);
}

/** @return resource */
function acquireLock(string $directory, int $maxParallelism)
{
    if (!\is_dir($directory) && !\mkdir($directory, 0700, true) && !\is_dir($directory)) {
        \fail(\sprintf('The local CI lock could not create directory "%s".', $directory));
    }

    $reportedWait = false;

    while (true) {
        for ($slot = 1; $slot <= $maxParallelism; $slot++) {
            $handle = \fopen($directory . '/slot-' . $slot . '.lock', 'c+');

            if ($handle === false) {
                \fail(\sprintf('The local CI lock could not open slot %d.', $slot));
            }

            if (\flock($handle, \LOCK_EX | \LOCK_NB)) {
                return $handle;
            }

            \fclose($handle);
        }

        if (!$reportedWait) {
            \fwrite(\STDERR, \sprintf(
                "All local CI slots are in use. The maximum parallelism is %d.\n",
                $maxParallelism,
            ));
            $reportedWait = true;
        }

        \usleep(100_000);
    }
}

/** @param non-empty-list<string> $command */
function run(array $command): int
{
    $process = \proc_open(
        $command,
        [
            0 => \STDIN,
            1 => \STDOUT,
            2 => \STDERR,
        ],
        $pipes,
        options: ['bypass_shell' => true],
    );

    if (!\is_resource($process)) {
        \fail(\sprintf('The local CI lock could not start command "%s".', $command[0]));
    }

    return \proc_close($process);
}

function fail(string $message): never
{
    \fwrite(\STDERR, $message . "\n");
    exit(2);
}

exit(\main(\cliArguments($_SERVER['argv'] ?? null)));

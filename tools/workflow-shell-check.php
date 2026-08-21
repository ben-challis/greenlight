<?php

declare(strict_types=1);

$root = \dirname(__DIR__);
$workflowFiles = [];
/** @var list<string> $arguments */
$arguments = $_SERVER['argv'] ?? [];

foreach (\array_slice($arguments, 1) as $argument) {
    if (\str_starts_with($argument, '-')) {
        \fwrite(\STDERR, \sprintf("Unknown workflow shell check option \"%s\".\n", $argument));

        exit(2);
    }

    $workflowFiles[] = $argument;
}

if ($workflowFiles === []) {
    $yamlFiles = \glob($root . '/.github/workflows/*.yaml');
    $ymlFiles = \glob($root . '/.github/workflows/*.yml');
    $workflowFiles = [
        ...($yamlFiles === false ? [] : $yamlFiles),
        ...($ymlFiles === false ? [] : $ymlFiles),
    ];
}

try {
    $violations = [];

    foreach ($workflowFiles as $workflowFile) {
        $displayPath = \displayWorkflowPath($workflowFile, $root);

        foreach (\multilineRunSteps($workflowFile) as $step) {
            if ($step['usesBash']) {
                continue;
            }

            $violations[] = \sprintf(
                '%s:%d: Multiline run step "%s" does not set `shell: bash`.',
                $displayPath,
                $step['line'],
                $step['name'],
            );
        }
    }
} catch (RuntimeException $error) {
    \fwrite(\STDERR, $error->getMessage() . "\n");

    exit(2);
}

if ($violations !== []) {
    foreach ($violations as $violation) {
        \fwrite(\STDERR, $violation . "\n");
    }

    \fwrite(\STDERR, "Set `shell: bash` on each multiline `run` step.\n");

    exit(1);
}

echo "Workflow shell contracts passed.\n";

/**
 * @return list<array{name: string, line: int, usesBash: bool}>
 */
function multilineRunSteps(string $path): array
{
    $contents = \file_get_contents($path);

    if ($contents === false) {
        throw new RuntimeException(\sprintf('Could not read workflow "%s".', $path));
    }

    $lines = \preg_split('/\R/', $contents);

    if ($lines === false) {
        throw new RuntimeException(\sprintf('Could not split workflow "%s" into lines.', $path));
    }

    $steps = [];

    foreach ($lines as $index => $line) {
        if (\preg_match('/\A( *)(- )?run:\s*[|>](?:[1-9][+-]?|[+-][1-9]?)?\s*(?:#.*)?\z/', $line, $match) !== 1) {
            continue;
        }

        $runStartsStep = isset($match[2]);
        $keyIndent = \strlen($match[1]) + ($runStartsStep ? 2 : 0);
        [$stepStart, $stepEnd, $stepIndent] = \stepBounds($lines, $index, $keyIndent, $runStartsStep);
        $name = \stepName($lines, $stepStart, $stepEnd, $keyIndent, $stepIndent, $index + 1);
        $usesBash = \stepUsesBash($lines, $stepStart, $stepEnd, $keyIndent, $stepIndent);

        $steps[] = [
            'name' => $name,
            'line' => $index + 1,
            'usesBash' => $usesBash,
        ];
    }

    return $steps;
}

/**
 * @param list<string> $lines
 *
 * @return array{int, int, int}
 */
function stepBounds(array $lines, int $runIndex, int $keyIndent, bool $runStartsStep): array
{
    $stepStart = $runIndex;
    $stepIndent = $runStartsStep ? $keyIndent - 2 : -1;

    if (!$runStartsStep) {
        for ($index = $runIndex - 1; $index >= 0; --$index) {
            if (\preg_match('/\A( *)-\s+/', $lines[$index], $match) !== 1) {
                continue;
            }

            $candidateIndent = \strlen($match[1]);

            if ($candidateIndent < $keyIndent) {
                $stepStart = $index;
                $stepIndent = $candidateIndent;

                break;
            }
        }
    }

    if ($stepIndent < 0) {
        throw new RuntimeException(\sprintf('Could not find the step for multiline run block on line %d.', $runIndex + 1));
    }

    $stepEnd = \count($lines);
    $lineCount = \count($lines);

    for ($index = $stepStart + 1; $index < $lineCount; ++$index) {
        $line = $lines[$index];

        if ($line === '') {
            continue;
        }

        $indent = \strlen($line) - \strlen(\ltrim($line, ' '));

        if ($indent < $stepIndent || ($indent === $stepIndent && \preg_match('/\A *-\s+/', $line) === 1)) {
            $stepEnd = $index;

            break;
        }
    }

    return [$stepStart, $stepEnd, $stepIndent];
}

/**
 * @param list<string> $lines
 */
function stepName(array $lines, int $start, int $end, int $keyIndent, int $stepIndent, int $line): string
{
    $keyPattern = '/\A {' . $keyIndent . '}name:\s*(.*?)\s*\z/';
    $inlinePattern = '/\A {' . $stepIndent . '}-\s+name:\s*(.*?)\s*\z/';

    for ($index = $start; $index < $end; ++$index) {
        if (
            \preg_match($keyPattern, $lines[$index], $match) !== 1
            && \preg_match($inlinePattern, $lines[$index], $match) !== 1
        ) {
            continue;
        }

        $name = $match[1];

        if (\strlen($name) >= 2 && (($name[0] === '"' && $name[-1] === '"') || ($name[0] === "'" && $name[-1] === "'"))) {
            return \substr($name, 1, -1);
        }

        return $name;
    }

    return 'line ' . $line;
}

/**
 * @param list<string> $lines
 */
function stepUsesBash(array $lines, int $start, int $end, int $keyIndent, int $stepIndent): bool
{
    $keyPattern = '/\A {' . $keyIndent . '}shell:\s*(?:bash|"bash"|\'bash\')\s*(?:#.*)?\z/';
    $inlinePattern = '/\A {' . $stepIndent . '}-\s+shell:\s*(?:bash|"bash"|\'bash\')\s*(?:#.*)?\z/';

    for ($index = $start; $index < $end; ++$index) {
        if (\preg_match($keyPattern, $lines[$index]) === 1 || \preg_match($inlinePattern, $lines[$index]) === 1) {
            return true;
        }
    }

    return false;
}

function displayWorkflowPath(string $path, string $root): string
{
    $absolutePath = \realpath($path);

    if ($absolutePath === false) {
        throw new RuntimeException(\sprintf('Workflow "%s" does not exist.', $path));
    }

    if (!\str_starts_with($path, \DIRECTORY_SEPARATOR)) {
        return $path;
    }

    $rootPrefix = $root . \DIRECTORY_SEPARATOR;

    return \str_starts_with($absolutePath, $rootPrefix)
        ? \substr($absolutePath, \strlen($rootPrefix))
        : $absolutePath;
}

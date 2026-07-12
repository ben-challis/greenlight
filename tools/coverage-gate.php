<?php

declare(strict_types=1);

/*
 * The self-coverage gate: reads the JSON export produced by
 * `composer tests:coverage` and fails when the framework's own line
 * coverage drops below the floor.
 */

const MIN_COVERAGE_PERCENTAGE = 78.0;

$root = \dirname(__DIR__);
$exportFile = $root . '/build/coverage/coverage.json';

if (!\is_file($exportFile)) {
    \fwrite(\STDERR, \sprintf(
        "Coverage export not found at %s. Run `composer tests:coverage` first.\n",
        $exportFile,
    ));
    exit(1);
}

$json = \file_get_contents($exportFile);

if ($json === false) {
    \fwrite(\STDERR, \sprintf("Failed to read coverage export at %s.\n", $exportFile));
    exit(1);
}

try {
    $document = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    \fwrite(\STDERR, \sprintf("Coverage export is not valid JSON: %s\n", $e->getMessage()));
    exit(1);
}

if (!\is_array($document) || !isset($document['totals']) || !\is_array($document['totals'])) {
    \fwrite(\STDERR, "Coverage export is missing a \"totals\" object.\n");
    exit(1);
}

$percentage = $document['totals']['percentage'] ?? null;

if (!\is_int($percentage) && !\is_float($percentage)) {
    \fwrite(\STDERR, "Coverage export is missing a numeric \"totals.percentage\".\n");
    exit(1);
}

\printf("Line coverage: %.2f%% (floor: %.2f%%)\n", $percentage, MIN_COVERAGE_PERCENTAGE);

if ($percentage < MIN_COVERAGE_PERCENTAGE) {
    \fwrite(\STDERR, \sprintf(
        "COVERAGE GATE FAILED: %.2f%% is below the %.2f%% floor.\n",
        $percentage,
        MIN_COVERAGE_PERCENTAGE,
    ));
    exit(1);
}

echo "Coverage gate passed.\n";
exit(0);

<?php

declare(strict_types=1);

/*
 * The self-coverage gate: reads the JSON export produced by
 * `composer tests:coverage` and fails when the framework's own line
 * coverage drops below the floor.
 */

const MIN_COVERAGE_PERCENTAGE = 78.0;
const LEAST_COVERED_FILE_LIMIT = 10;

$root = \dirname(__DIR__);
$exportFile = $root . '/build/coverage/coverage.json';
$summaryFile = \getenv('GITHUB_STEP_SUMMARY');
$summaryFile = \is_string($summaryFile) && $summaryFile !== '' ? $summaryFile : null;

if (!\is_file($exportFile)) {
    \appendGithubSummary($summaryFile, \unavailableSummary('The coverage export was not produced.'));
    \fwrite(\STDERR, \sprintf(
        "Coverage export not found at %s. Run `composer tests:coverage` first.\n",
        $exportFile,
    ));
    exit(1);
}

$json = \file_get_contents($exportFile);

if ($json === false) {
    \appendGithubSummary($summaryFile, \unavailableSummary('The coverage export could not be read.'));
    \fwrite(\STDERR, \sprintf("Failed to read coverage export at %s.\n", $exportFile));
    exit(1);
}

try {
    $document = \json_decode($json, true, 512, \JSON_THROW_ON_ERROR);
} catch (JsonException $e) {
    \appendGithubSummary($summaryFile, \unavailableSummary('The coverage export is not valid JSON.'));
    \fwrite(\STDERR, \sprintf("Coverage export is not valid JSON: %s\n", $e->getMessage()));
    exit(1);
}

if (!\is_array($document) || !isset($document['totals']) || !\is_array($document['totals'])) {
    \appendGithubSummary($summaryFile, \unavailableSummary('The coverage export is missing aggregate totals.'));
    \fwrite(\STDERR, "Coverage export is missing a \"totals\" object.\n");
    exit(1);
}

$percentage = $document['totals']['percentage'] ?? null;

if (!\is_int($percentage) && !\is_float($percentage)) {
    \appendGithubSummary($summaryFile, \unavailableSummary('The coverage export is missing the aggregate percentage.'));
    \fwrite(\STDERR, "Coverage export is missing a numeric \"totals.percentage\".\n");
    exit(1);
}

\appendGithubSummary(
    $summaryFile,
    \coverageSummary($document, $root, (float) $percentage, MIN_COVERAGE_PERCENTAGE),
);

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

/**
 * @param array<mixed> $document
 */
function coverageSummary(array $document, string $root, float $percentage, float $floor): string
{
    $totals = $document['totals'];
    \assert(\is_array($totals));

    $coveredLines = $totals['coveredLines'] ?? null;
    $executableLines = $totals['executableLines'] ?? null;
    $lineCount = \is_int($coveredLines) && \is_int($executableLines)
        ? \sprintf('%s / %s', \number_format($coveredLines), \number_format($executableLines))
        : 'Unavailable';
    $passed = $percentage >= $floor;

    $summary = \sprintf(
        <<<'MARKDOWN'
        ## Code coverage

        | Metric | Result |
        | --- | ---: |
        | Line coverage | **%.2f%%** |
        | Covered lines | %s |
        | Required floor | %.2f%% |
        | Coverage threshold | %s |

        _The coverage threshold is separate from the overall CI result._

        MARKDOWN,
        $percentage,
        $lineCount,
        $floor,
        $passed ? '✅ Passed' : '❌ Failed',
    );
    $summary .= "\n";

    $files = \leastCoveredFiles($document, $root);

    if ($files === []) {
        return $summary;
    }

    $summary .= \sprintf(
        <<<'MARKDOWN'
        ### %d least-covered files

        | File | Coverage | Covered | Missed |
        | --- | ---: | ---: | ---: |

        MARKDOWN,
        \count($files),
    );

    foreach ($files as $file) {
        $summary .= \sprintf(
            "| %s | %.2f%% | %s / %s | %s |\n",
            \markdownCode($file['path']),
            $file['percentage'],
            \number_format($file['covered']),
            \number_format($file['executable']),
            \number_format($file['missed']),
        );
    }

    return $summary . "\n";
}

/**
 * @param array<mixed> $document
 *
 * @return list<array{path: string, percentage: float, covered: int, executable: int, missed: int}>
 */
function leastCoveredFiles(array $document, string $root): array
{
    $entries = $document['files'] ?? null;

    if (!\is_array($entries)) {
        return [];
    }

    $rootPrefix = \rtrim($root, \DIRECTORY_SEPARATOR) . \DIRECTORY_SEPARATOR;
    $files = [];

    foreach ($entries as $path => $entry) {
        if (
            !\is_string($path)
            || !\str_starts_with($path, $rootPrefix)
            || !\is_array($entry)
            || !isset($entry['covered'], $entry['uncovered'])
            || !\is_array($entry['covered'])
            || !\is_array($entry['uncovered'])
        ) {
            continue;
        }

        $covered = \count($entry['covered']);
        $missed = \count($entry['uncovered']);
        $executable = $covered + $missed;

        if ($executable === 0) {
            continue;
        }

        $files[] = [
            'path' => \substr($path, \strlen($rootPrefix)),
            'percentage' => \round($covered / $executable * 100, 2),
            'covered' => $covered,
            'executable' => $executable,
            'missed' => $missed,
        ];
    }

    \usort(
        $files,
        static function (array $left, array $right): int {
            $percentageOrder = $left['percentage'] <=> $right['percentage'];

            if ($percentageOrder !== 0) {
                return $percentageOrder;
            }

            $missedOrder = $right['missed'] <=> $left['missed'];

            return $missedOrder !== 0 ? $missedOrder : $left['path'] <=> $right['path'];
        },
    );

    return \array_slice($files, 0, LEAST_COVERED_FILE_LIMIT);
}

function markdownCode(string $value): string
{
    $value = \str_replace(["\r", "\n"], ' ', $value);
    $value = \htmlspecialchars($value, \ENT_QUOTES | \ENT_SUBSTITUTE, 'UTF-8');

    return '<code>' . \str_replace(['|', '`'], ['&#124;', '&#96;'], $value) . '</code>';
}

function unavailableSummary(string $reason): string
{
    return \sprintf(
        "## Code coverage\n\n**Coverage unavailable.** %s\n\n",
        $reason,
    );
}

function appendGithubSummary(?string $summaryFile, string $summary): void
{
    if ($summaryFile === null) {
        return;
    }

    if (@\file_put_contents($summaryFile, $summary, \FILE_APPEND | \LOCK_EX) === false) {
        \fwrite(\STDERR, "Warning: Could not write the GitHub Actions job summary.\n");
    }
}

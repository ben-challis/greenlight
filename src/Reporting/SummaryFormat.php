<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;

/**
 * The end-of-run summary fragments shared by the human-facing reporters.
 *
 * tests() renders the one-line result totals, omitting zero-value categories
 * and colouring passed, failed, errored and skipped through the style.
 * workers() renders the worker line, dropping a zero recycled count and
 * returning null when no workers were spawned at all. skipped() lists every
 * skipped test with its reason, grouping tests that share a reason and
 * capping each group at five ids.
 * leaks() lists every leaked test under one red header.
 * coverage() renders the line-coverage percentage in green with the covered
 * and executable line counts. coverageExport() renders one written export as
 * an indented format-and-target line.
 *
 * @internal
 */
final class SummaryFormat
{
    private const int MAX_IDS_PER_GROUP = 5;

    private function __construct() {}

    public static function tests(ResultSummary $summary, int $expectations, Style $style): string
    {
        $parts = [Plural::count($summary->total(), 'test')];

        $passed = \sprintf('%d passed', $summary->passed);
        $parts[] = $summary->passed > 0 ? $style->ok($passed) : $passed;

        if ($summary->failed > 0) {
            $parts[] = $style->error(\sprintf('%d failed', $summary->failed));
        }

        if ($summary->errored > 0) {
            $parts[] = $style->error(\sprintf('%d errored', $summary->errored));
        }

        if ($summary->skipped > 0) {
            $parts[] = $style->warn(\sprintf('%d skipped', $summary->skipped));
        }

        $parts[] = Plural::count($expectations, 'expectation');

        return \implode(', ', $parts);
    }

    public static function workers(int $spawned, int $recycled, string $recycledSuffix = ''): ?string
    {
        if ($spawned === 0) {
            return null;
        }

        $line = \sprintf('Workers: %d spawned', $spawned);

        if ($recycled > 0) {
            $line .= \sprintf(', %d recycled%s', $recycled, $recycledSuffix);
        }

        return $line;
    }

    /**
     * @param list<TestResult> $skipped
     */
    public static function skipped(array $skipped, Style $style): string
    {
        if ($skipped === []) {
            return '';
        }

        $groups = [];

        foreach ($skipped as $result) {
            $reason = $result->skipReason ?? 'no reason given';
            $groups[$reason][] = $result;
        }

        $lines = ["\n" . $style->warn('Skipped:')];

        foreach ($groups as $reason => $results) {
            if (\count($results) === 1) {
                $lines[] = \sprintf('  %s (%s)', $results[0]->id, $reason);

                continue;
            }

            $lines[] = \sprintf('  %s:', $reason);

            foreach (\array_slice($results, 0, self::MAX_IDS_PER_GROUP) as $result) {
                $lines[] = '    ' . $result->id;
            }

            if (\count($results) > self::MAX_IDS_PER_GROUP) {
                $lines[] = \sprintf('    … and %d more', \count($results) - self::MAX_IDS_PER_GROUP);
            }
        }

        return \implode("\n", $lines) . "\n";
    }

    public static function coverage(float $percentage, int $coveredLines, int $executableLines, Style $style): string
    {
        return \sprintf(
            'Coverage: %s (%d of %s)',
            $style->ok(\sprintf('%.2f%%', $percentage)),
            $coveredLines,
            Plural::count($executableLines, 'line'),
        );
    }

    public static function coverageExport(string $format, string $target): string
    {
        return \sprintf('  %s → %s', $format, $target);
    }

    /**
     * @param list<TestId> $leaks
     */
    public static function leaks(array $leaks, Style $style): string
    {
        if ($leaks === []) {
            return '';
        }

        $lines = ["\n" . $style->error('Leaks (the test instance survived its test):')];

        foreach ($leaks as $leak) {
            $lines[] = '  ' . $leak;
        }

        return \implode("\n", $lines) . "\n";
    }
}

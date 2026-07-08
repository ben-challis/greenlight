<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;

/**
 * The end-of-run summary fragments shared by the human-facing reporters.
 *
 * tests() renders the one-line result totals, omitting zero-value categories
 * and colouring passed, failed, errored and skipped through the style.
 * workers() renders the worker line, dropping a zero recycled count and
 * returning null when no workers were spawned at all. skipped() lists every
 * skipped test with its reason, grouping tests that share a reason and
 * capping each group at five ids, so a skip is never just a number.
 *
 * @internal
 */
final class SummaryFormat
{
    private function __construct() {}

    public static function tests(ResultSummary $summary, int $expectations, Style $style): string
    {
        $parts = [Style::count($summary->total(), 'test')];

        $passed = \sprintf('%d passed', $summary->passed);
        $parts[] = $summary->passed > 0 ? $style->pass($passed) : $passed;

        if ($summary->failed > 0) {
            $parts[] = $style->fail(\sprintf('%d failed', $summary->failed));
        }

        if ($summary->errored > 0) {
            $parts[] = $style->fail(\sprintf('%d errored', $summary->errored));
        }

        if ($summary->skipped > 0) {
            $parts[] = $style->skip(\sprintf('%d skipped', $summary->skipped));
        }

        $parts[] = Style::count($expectations, 'expectation');

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

        $lines = ["\n" . $style->skip('Skipped:')];

        foreach ($groups as $reason => $results) {
            if (\count($results) === 1) {
                $lines[] = \sprintf('  %s (%s)', $results[0]->id, $reason);

                continue;
            }

            $lines[] = \sprintf('  %s:', $reason);

            foreach (\array_slice($results, 0, 5) as $result) {
                $lines[] = '    ' . $result->id;
            }

            if (\count($results) > 5) {
                $lines[] = \sprintf('    … and %d more', \count($results) - 5);
            }
        }

        return \implode("\n", $lines) . "\n";
    }
}

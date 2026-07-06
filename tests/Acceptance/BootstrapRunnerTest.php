<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Tests\Support\Check;

/**
 * Runs the bootstrap runner as a subprocess against fixture suites and asserts
 * on observable behaviour only (exit code, output), so a broken runner fails
 * loudly instead of reporting green. Fixtures print "marker:" lines to stdout;
 * the sequence of those lines is the observable hook order.
 */
final class BootstrapRunnerTest
{
    #[Test]
    public function passingSuiteExitsZeroAndRunsHooksInOrder(): void
    {
        [$exit, $markers] = $this->runFixtureSuite('tests/Fixture/BootstrapPassing');

        Check::same(0, $exit, 'passing suite exit code');
        Check::same(['marker:before', 'marker:test', 'marker:after'], $markers, 'hook order');
    }

    #[Test]
    public function failingSuiteExitsOneAndStillRunsAfterHooks(): void
    {
        [$exit, $markers] = $this->runFixtureSuite('tests/Fixture/BootstrapFailing');

        Check::same(1, $exit, 'failing suite exit code');
        Check::same(['marker:after'], $markers, 'the #[After] hook runs even when the test fails');
    }

    #[Test]
    public function emptySuiteExitsNonZero(): void
    {
        [$exit] = $this->runFixtureSuite('tests/Fixture/BootstrapEmpty');

        Check::same(1, $exit, 'a run that finds no tests must fail');
    }

    /**
     * @return array{int, list<string>}
     */
    private function runFixtureSuite(string $relativeDir): array
    {
        $root = \dirname(__DIR__, 2);

        $command = \sprintf(
            '%s %s %s 2>&1',
            \escapeshellarg(\PHP_BINARY),
            \escapeshellarg($root . '/tools/bootstrap-runner.php'),
            \escapeshellarg($root . '/' . $relativeDir),
        );

        \exec($command, $output, $exit);

        $markers = \array_values(\array_filter($output, static fn(string $line): bool => \str_starts_with($line, 'marker:')));

        return [$exit, $markers];
    }
}

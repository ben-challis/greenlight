<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\ResultSummary;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Style;
use Greenlight\Reporting\SummaryFormat;

final class SummaryFormatTest
{
    #[Test]
    public function testsRendersEveryOutcomeCount(): void
    {
        $line = SummaryFormat::tests(
            new ResultSummary(passed: 2, failed: 1, errored: 1, skipped: 1),
            7,
            new Style(ansi: false),
        );

        Expect::that($line)
            ->because(
                'the final summary shows each test outcome and the expectation count',
            )
            ->toBe('5 tests, 2 passed, 1 failed, 1 errored, 1 skipped, 7 expectations');
    }

    #[Test]
    public function testsOmitsZeroOutcomesAndUsesSingularCounts(): void
    {
        $line = SummaryFormat::tests(
            new ResultSummary(passed: 1),
            1,
            new Style(ansi: false),
        );

        Expect::that($line)
            ->because(
                'the final summary omits unused outcomes and singularizes counts',
            )
            ->toBe('1 test, 1 passed, 1 expectation');
    }

    #[Test]
    public function aSingleTestPerReasonStaysInline(): void
    {
        $block = SummaryFormat::skipped([
            $this->skip('App\AlphaTest::one', 'needs redis'),
            $this->skip('App\BetaTest::two', null),
        ], new Style(ansi: false));

        Expect::that($block)->because('a single test per reason stays inline')->toBe(
            "\nSkipped:\n"
            . "  App\AlphaTest::one (needs redis)\n"
            . "  App\BetaTest::two (no reason given)\n",
        );
    }

    #[Test]
    public function zeroStringSkipReasonsRemainDistinctFromNoReason(): void
    {
        $block = SummaryFormat::skipped([
            $this->skip('App\AlphaTest::one', '0'),
        ], new Style(ansi: false));

        Expect::that($block)
            ->because('a zero-string skip reason MUST remain distinct from a missing reason')
            ->toBe(
                "\nSkipped:\n"
                . "  App\AlphaTest::one (0)\n",
            );
    }

    #[Test]
    public function sharedReasonsGroupWithACap(): void
    {
        $results = [];

        for ($i = 1; $i <= 7; ++$i) {
            $results[] = $this->skip(\sprintf('App\GammaTest::case%d', $i), 'xdebug not loaded');
        }

        $block = SummaryFormat::skipped($results, new Style(ansi: false));

        Expect::that($block)->because('shared reasons group with a cap')->toContain("  xdebug not loaded:\n    App\GammaTest::case1\n")
            ->toContain("    App\GammaTest::case5\n")
            ->not()->toContain('case6')
            ->toContain('    … and 2 more');
    }

    #[Test]
    public function exactlyFiveListsAllWithoutOverflow(): void
    {
        $five = [];

        for ($i = 1; $i <= 5; ++$i) {
            $five[] = $this->skip(\sprintf('App\DeltaTest::case%d', $i), 'shared reason');
        }

        Expect::that(SummaryFormat::skipped($five, new Style(ansi: false)))->because('exactly five lists all without overflow')->toBe(
            "\nSkipped:\n"
            . "  shared reason:\n"
            . "    App\DeltaTest::case1\n"
            . "    App\DeltaTest::case2\n"
            . "    App\DeltaTest::case3\n"
            . "    App\DeltaTest::case4\n"
            . "    App\DeltaTest::case5\n",
        );
    }

    #[Test]
    public function sixListsFiveAndReportsOneOverflow(): void
    {
        $six = [];

        for ($i = 1; $i <= 6; ++$i) {
            $six[] = $this->skip(\sprintf('App\DeltaTest::case%d', $i), 'shared reason');
        }

        Expect::that(SummaryFormat::skipped($six, new Style(ansi: false)))->because('six lists five and reports one overflow')->toBe(
            "\nSkipped:\n"
            . "  shared reason:\n"
            . "    App\DeltaTest::case1\n"
            . "    App\DeltaTest::case2\n"
            . "    App\DeltaTest::case3\n"
            . "    App\DeltaTest::case4\n"
            . "    App\DeltaTest::case5\n"
            . "    … and 1 more\n",
        );
    }

    #[Test]
    public function leaksListEveryTestUnderOneHeader(): void
    {
        $block = SummaryFormat::leaks([
            new TestId('App\AlphaTest', 'one'),
            new TestId('App\BetaTest', 'two'),
        ], new Style(ansi: false));

        Expect::that($block)->because('leaks list every test under one header')->toBe(
            "\nTest instance leaks:\n"
            . "  App\AlphaTest::one\n"
            . "  App\BetaTest::two\n",
        );
    }

    #[Test]
    public function leaksColorTheHeaderRedAndNothingWithoutLeaks(): void
    {
        $block = SummaryFormat::leaks([new TestId('App\AlphaTest', 'one')], new Style(ansi: true));

        Expect::that($block)->because('leaks color the header red and nothing without leaks')->toContain("\x1b[31mTest instance leaks:\x1b[0m");
        Expect::that(SummaryFormat::leaks([], new Style(ansi: true)))->toBe('');
    }

    #[Test]
    public function coverageShowsCoveredOfExecutableLines(): void
    {
        $line = SummaryFormat::coverage(88.3, 5283, 5983, new Style(ansi: false));

        Expect::that($line)->because('coverage shows covered of executable lines')->toBe('Coverage: 88.30% (5283 of 5983 lines)');
    }

    #[Test]
    public function coverageSingularizesASingleExecutableLine(): void
    {
        $line = SummaryFormat::coverage(100.0, 1, 1, new Style(ansi: false));

        Expect::that($line)->because('coverage singularizes a single executable line')->toBe('Coverage: 100.00% (1 of 1 line)');
    }

    #[Test]
    public function coverageColorsThePercentageGreen(): void
    {
        $line = SummaryFormat::coverage(88.3, 5283, 5983, new Style(ansi: true));

        Expect::that($line)->because('coverage colors the percentage green')->toContain("\x1b[32m88.30%\x1b[0m");
    }

    #[Test]
    public function coverageExportRendersAnIndentedFormatAndTargetLine(): void
    {
        $line = SummaryFormat::coverageExport('json', 'build/coverage/coverage.json');

        Expect::that($line)->because('coverage export renders an indented format and target line')->toBe('  json → build/coverage/coverage.json');
    }

    /**
     * @param non-empty-string $id
     */
    private function skip(string $id, ?string $reason): TestResult
    {
        [$class, $method] = \explode('::', $id);
        \assert($class !== '' && $method !== '');

        return new TestResult(new TestId($class, $method), Outcome::Skipped, 0.0, 0, skipReason: $reason);
    }
}

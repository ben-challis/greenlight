<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\Style;
use Greenlight\Reporting\SummaryFormat;

final class SummaryFormatTest
{
    #[Test]
    public function aSingleTestPerReasonStaysInline(): void
    {
        $block = SummaryFormat::skipped([
            $this->skip('App\AlphaTest::one', 'needs redis'),
            $this->skip('App\BetaTest::two', null),
        ], new Style(ansi: false));

        Expect::that($block)->toBe(
            "\nSkipped:\n"
            . "  App\AlphaTest::one (needs redis)\n"
            . "  App\BetaTest::two (no reason given)\n",
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

        Expect::that($block)->toContain("  xdebug not loaded:\n    App\GammaTest::case1\n")
            ->and($block)->toContain("    App\GammaTest::case5\n")
            ->and($block)->not()->toContain('case6')
            ->and($block)->toContain('    … and 2 more');
    }

    #[Test]
    public function exactlyFiveListsAllWithoutOverflowAndSixOverflowsByOne(): void
    {
        $five = [];

        for ($i = 1; $i <= 5; ++$i) {
            $five[] = $this->skip(\sprintf('App\DeltaTest::case%d', $i), 'shared reason');
        }

        Expect::that(SummaryFormat::skipped($five, new Style(ansi: false)))->toBe(
            "\nSkipped:\n"
            . "  shared reason:\n"
            . "    App\DeltaTest::case1\n"
            . "    App\DeltaTest::case2\n"
            . "    App\DeltaTest::case3\n"
            . "    App\DeltaTest::case4\n"
            . "    App\DeltaTest::case5\n",
        );

        $six = [...$five, $this->skip('App\DeltaTest::case6', 'shared reason')];

        Expect::that(SummaryFormat::skipped($six, new Style(ansi: false)))->toContain('… and 1 more');
    }

    #[Test]
    public function leaksListEveryTestUnderOneHeader(): void
    {
        $block = SummaryFormat::leaks([
            new TestId('App\AlphaTest', 'one'),
            new TestId('App\BetaTest', 'two'),
        ], new Style(ansi: false));

        Expect::that($block)->toBe(
            "\nLeaks (the test instance survived its test):\n"
            . "  App\AlphaTest::one\n"
            . "  App\BetaTest::two\n",
        );
    }

    #[Test]
    public function leaksColourTheHeaderRedAndNothingWithoutLeaks(): void
    {
        $block = SummaryFormat::leaks([new TestId('App\AlphaTest', 'one')], new Style(ansi: true));

        Expect::that($block)->toContain("\x1b[31mLeaks (the test instance survived its test):\x1b[0m")
            ->and(SummaryFormat::leaks([], new Style(ansi: true)))->toBe('');
    }

    #[Test]
    public function coverageShowsCoveredOfExecutableLines(): void
    {
        $line = SummaryFormat::coverage(88.3, 5283, 5983, new Style(ansi: false));

        Expect::that($line)->toBe('Coverage: 88.30% (5283 of 5983 lines)');
    }

    #[Test]
    public function coverageSingularisesASingleExecutableLine(): void
    {
        $line = SummaryFormat::coverage(100.0, 1, 1, new Style(ansi: false));

        Expect::that($line)->toBe('Coverage: 100.00% (1 of 1 line)');
    }

    #[Test]
    public function coverageColoursThePercentageGreen(): void
    {
        $line = SummaryFormat::coverage(88.3, 5283, 5983, new Style(ansi: true));

        Expect::that($line)->toContain("\x1b[32m88.30%\x1b[0m");
    }

    #[Test]
    public function coverageExportRendersAnIndentedFormatAndTargetLine(): void
    {
        $line = SummaryFormat::coverageExport('json', 'build/coverage/coverage.json');

        Expect::that($line)->toBe('  json → build/coverage/coverage.json');
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

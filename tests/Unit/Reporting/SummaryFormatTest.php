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

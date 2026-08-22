<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Event\TestFinished;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\SlowTests;
use Greenlight\Reporting\Style;
use Greenlight\Result\Outcome;
use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

final class SlowTestsTest
{
    #[Test]
    public function fastRunsRenderNothing(): void
    {
        $slow = new SlowTests();
        $slow->record($this->finished('Acme\FastTest', 'quick', 0.4));

        Expect::that($slow->render(new Style(ansi: false)))->because('fast runs render nothing')->toBe('');
    }

    #[Test]
    public function rendersSlowestFirstAndCapsAtFive(): void
    {
        $slow = new SlowTests();

        for ($i = 1; $i <= 8; ++$i) {
            $slow->record($this->finished('Acme\SlowTest', \sprintf('case%02d', $i), 0.5 + $i / 100));
        }

        $rendered = $slow->render(new Style(ansi: false));
        $lines = \array_values(\array_filter(
            \explode("\n", \trim($rendered)),
            static fn(string $line): bool => $line !== '',
        ));

        Expect::that($lines[0])->because('renders slowest first and caps at five')->toBe('Slowest tests:');
        Expect::that(\count($lines))->toBe(6);
        Expect::that($lines[1])->toBe('  0.580s Acme\SlowTest::case08');
        Expect::that($lines[5])->toBe('  0.540s Acme\SlowTest::case04');
        Expect::that($rendered)->not()->toContain('case03');
    }

    #[Test]
    public function extendedModeKeepsMoreEntries(): void
    {
        $slow = new SlowTests(extended: true);

        for ($i = 1; $i <= 8; ++$i) {
            $slow->record($this->finished('Acme\SlowTest', \sprintf('case%02d', $i), 0.5 + $i / 100));
        }

        Expect::that($slow->render(new Style(ansi: false)))->because('extended mode keeps more entries')->toContain('case01');
    }

    #[Test]
    public function extendedModeCapsAtTwentyFive(): void
    {
        $slow = new SlowTests(extended: true);

        for ($i = 1; $i <= 26; ++$i) {
            $slow->record($this->finished('Acme\SlowTest', \sprintf('case%02d', $i), 0.5 + $i / 100));
        }

        $rendered = $slow->render(new Style(ansi: false));
        $lines = \explode("\n", \trim($rendered));

        Expect::that(\count($lines))
            ->because('profile mode MUST list exactly the 25 slowest tests')
            ->toBe(26);
        Expect::that($lines[1])
            ->toBe('  0.760s Acme\SlowTest::case26');
        Expect::that($lines[25])
            ->toBe('  0.520s Acme\SlowTest::case02');
        Expect::that($rendered)
            ->not()
            ->toContain('case01');
    }

    #[Test]
    public function longRunsPruneIncrementallyWithoutLosingTheSlowestTests(): void
    {
        $slow = new SlowTests();

        for ($i = 1; $i <= 21; ++$i) {
            $slow->record($this->finished('Acme\SlowTest', \sprintf('case%02d', $i), 0.5 + $i / 100));
        }

        $slow->record($this->finished('Acme\SlowTest', 'lateSlowest', 0.95));
        $slow->record($this->finished('Acme\SlowTest', 'lateButFaster', 0.51));
        $rendered = $slow->render(new Style(ansi: false));

        Expect::that($rendered)
            ->because('incremental pruning MUST retain the globally slowest tests')
            ->toContain('0.950s Acme\SlowTest::lateSlowest')
            ->toContain('0.710s Acme\SlowTest::case21')
            ->toContain('0.680s Acme\SlowTest::case18')
            ->not()
            ->toContain('lateButFaster')
            ->not()
            ->toContain('case17');
    }

    #[Test]
    public function durationsAreColoredThroughTheStyle(): void
    {
        $slow = new SlowTests();
        $slow->record($this->finished('Acme\SlowTest', 'crawls', 1.5));

        Expect::that($slow->render(new Style(ansi: true)))->because('durations are colored through the style')->toContain("\x1b[33m1.500s\x1b[0m");
    }

    /**
     * @param non-empty-string $class
     * @param non-empty-string $method
     */
    private function finished(string $class, string $method, float $duration): TestFinished
    {
        return new TestFinished(new TestResult(new TestId($class, $method), Outcome::Passed, $duration, 0), 1.0);
    }
}

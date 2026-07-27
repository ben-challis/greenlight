<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\SlowTests;
use Greenlight\Reporting\Style;

final class SlowTestsTest
{
    #[Test]
    public function fastRunsRenderNothing(): void
    {
        $slow = new SlowTests();
        $slow->record($this->finished('Acme\FastTest::quick', 0.4));

        Expect::that($slow->render(new Style(ansi: false)))->because('fast runs render nothing')->toBe('');
    }

    #[Test]
    public function rendersSlowestFirstAndCapsAtFive(): void
    {
        $slow = new SlowTests();

        for ($i = 1; $i <= 8; ++$i) {
            $slow->record($this->finished(\sprintf('Acme\SlowTest::case%02d', $i), 0.5 + $i / 100));
        }

        $rendered = $slow->render(new Style(ansi: false));
        $lines = \array_values(\array_filter(
            \explode("\n", \trim($rendered)),
            static fn(string $line): bool => $line !== '',
        ));

        Expect::that($lines[0])->because('renders slowest first and caps at five')->toBe('Slowest tests:')
            ->and(\count($lines))->toBe(6)
            ->and($lines[1])->toBe('  0.580s Acme\SlowTest::case08')
            ->and($lines[5])->toBe('  0.540s Acme\SlowTest::case04')
            ->and($rendered)->not()->toContain('case03');
    }

    #[Test]
    public function extendedModeKeepsMoreEntries(): void
    {
        $slow = new SlowTests(extended: true);

        for ($i = 1; $i <= 8; ++$i) {
            $slow->record($this->finished(\sprintf('Acme\SlowTest::case%02d', $i), 0.5 + $i / 100));
        }

        Expect::that($slow->render(new Style(ansi: false)))->because('extended mode keeps more entries')->toContain('case01');
    }

    #[Test]
    public function durationsAreColoredThroughTheStyle(): void
    {
        $slow = new SlowTests();
        $slow->record($this->finished('Acme\SlowTest::crawls', 1.5));

        Expect::that($slow->render(new Style(ansi: true)))->because('durations are colored through the style')->toContain("\x1b[33m1.500s\x1b[0m");
    }

    /**
     * @param non-empty-string $id
     */
    private function finished(string $id, float $duration): TestFinished
    {
        [$class, $method] = \explode('::', $id);
        \assert($class !== '' && $method !== '');

        return new TestFinished(new TestResult(new TestId($class, $method), Outcome::Passed, $duration, 0), 1.0);
    }
}

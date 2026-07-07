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

final class SlowTestsTest
{
    #[Test]
    public function fastRunsRenderNothing(): void
    {
        $slow = new SlowTests();
        $slow->record($this->finished('Acme\FastTest::quick', 0.05));

        new Expect()->that($slow->render())->toBe('');
    }

    #[Test]
    public function rendersSlowestFirstAndCapsAtTen(): void
    {
        $slow = new SlowTests();

        for ($i = 1; $i <= 12; ++$i) {
            $slow->record($this->finished(\sprintf('Acme\SlowTest::case%02d', $i), 0.2 + $i / 100));
        }

        $rendered = $slow->render();
        $lines = \array_values(\array_filter(
            \explode("\n", \trim($rendered)),
            static fn(string $line): bool => $line !== '',
        ));

        new Expect()->that($lines[0])->toBe('Slowest tests:')
            ->and(\count($lines))->toBe(11)
            ->and($lines[1])->toBe('  0.320s Acme\SlowTest::case12')
            ->and($lines[10])->toBe('  0.230s Acme\SlowTest::case03')
            ->and($rendered)->not()->toContain('case01');
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

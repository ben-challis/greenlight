<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Expect\Expect;

final class RunEventWireBoundsTest
{
    #[Test]
    #[DataSet('runStartedBounds')]
    public function runStartedWireFieldsNormalizeToSafeBounds(string $field, int $wireValue, int $expected): void
    {
        $payload = [
            'runId' => 'run-1',
            'plannedTests' => 10,
            'workers' => 2,
            'occurredAt' => 1_780_000_000.0,
            'artifactsDirectory' => null,
        ];
        $payload[$field] = $wireValue;

        Expect::that(RunStarted::fromWire($payload)->toWire()[$field])
            ->because('run-started wire fields MUST normalize to safe bounds')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, int, int}>
     */
    public static function runStartedBounds(): iterable
    {
        yield 'negative planned tests' => ['plannedTests', -1, 0];
        yield 'zero workers' => ['workers', 0, 1];
        yield 'negative workers' => ['workers', -1, 1];
    }

    #[Test]
    #[DataSet('runFinishedBounds')]
    public function runFinishedWireFieldsNormalizeToSafeBounds(string $field, float $wireValue, float $expected): void
    {
        $payload = [
            'runId' => 'run-1',
            'summary' => [
                'passed' => 1,
                'failed' => 0,
                'errored' => 0,
                'skipped' => 0,
            ],
            'durationSeconds' => 1.0,
            'occurredAt' => 1_780_000_001.0,
        ];
        $payload[$field] = $wireValue;

        Expect::that(RunFinished::fromWire($payload)->toWire()[$field])
            ->because('run-finished wire fields MUST normalize to safe bounds')
            ->toBe($expected);
    }

    /**
     * @return iterable<string, array{non-empty-string, float, float}>
     */
    public static function runFinishedBounds(): iterable
    {
        yield 'negative duration' => ['durationSeconds', -0.25, 0.0];
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Result\ResultSummary;
use Greenlight\Wire\InvalidWirePayload;

final class ResultSummaryWireTest
{
    #[Test]
    #[DataSet('summaryFields')]
    public function negativeWireCountsNormalizeToZero(string $field): void
    {
        $payload = $this->payload();
        $payload[$field] = -1;

        Expect::that(ResultSummary::fromWire($payload)->toWire()[$field])
            ->because('negative wire counts normalize to zero')
            ->toBe(0);
    }

    #[Test]
    #[DataSet('summaryFields')]
    public function nonIntegerWireCountsNameTheirField(string $field): void
    {
        $payload = $this->payload();
        $payload[$field] = 'one';

        Expect::that(static fn(): ResultSummary => ResultSummary::fromWire($payload))
            ->because('non-integer wire counts name their field')
            ->toThrow(
                InvalidWirePayload::class,
                message: \sprintf(
                    'Wire payload key "%s" must be an integer, got string.',
                    $field,
                ),
            );
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function summaryFields(): iterable
    {
        yield 'passed' => ['passed'];
        yield 'failed' => ['failed'];
        yield 'errored' => ['errored'];
        yield 'skipped' => ['skipped'];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return new ResultSummary(passed: 1, failed: 2, errored: 3, skipped: 4)->toWire();
    }
}

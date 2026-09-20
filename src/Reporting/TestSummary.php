<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

use Greenlight\Result\TestResult;
use Greenlight\Test\TestId;

/**
 * Retains only the fields that skipped and retried-pass footers use.
 *
 * @internal
 */
final readonly class TestSummary
{
    public TestId $id;

    /** @var positive-int */
    public int $attempts;

    public ?string $skipReason;

    public function __construct(TestResult $result)
    {
        $this->id = $result->id;
        $this->attempts = $result->attempts;
        $this->skipReason = $result->skipReason;
    }
}

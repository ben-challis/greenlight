<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Support;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Tests\Support\GreenlightCli;
use Greenlight\Tests\Support\ProcessResult;

final class GreenlightCliTest
{
    #[Test]
    public function summaryLineReturnsTheRunSummaryFromCombinedOutput(): void
    {
        $result = new ProcessResult(
            1,
            "Greenlight dev-main\n3 tests, 2 passed, 1 errored, 4 expectations",
            'extension diagnostic',
        );

        Expect::that(GreenlightCli::summaryLine($result))
            ->because('summary parsing MUST use the complete normalized process output')
            ->toBe('3 tests, 2 passed, 1 errored, 4 expectations');
    }

    #[Test]
    public function missingSummaryReportsTheCompleteOutput(): void
    {
        $result = new ProcessResult(1, 'run failed early', 'extension diagnostic');

        Expect::that(static fn(): string => GreenlightCli::summaryLine($result))
            ->because('a missing summary MUST preserve the process output for diagnosis')
            ->toThrow(static function (ExpectationFailed $failure): void {
                Expect::that($failure->detail()->message)
                    ->toBe("No summary line found in output:\nrun failed early\nextension diagnostic");
            });
    }
}

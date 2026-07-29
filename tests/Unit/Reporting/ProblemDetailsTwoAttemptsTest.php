<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Reporting;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\Outcome;
use Greenlight\Core\Result\TestResult;
use Greenlight\Core\Test\TestId;
use Greenlight\Expect\Expect;
use Greenlight\Reporting\ProblemDetails;

final readonly class ProblemDetailsTwoAttemptsTest
{
    #[Test]
    public function theFirstRetryIsReported(): void
    {
        $result = new TestResult(
            new TestId('Acme\\FlakyTest', 'failsTwice'),
            Outcome::Failed,
            0.1,
            0,
            attempts: 2,
        );

        Expect::that(ProblemDetails::render($result))
            ->because('a retried result MUST report its total attempt count')
            ->toBe("  after 2 attempts\n");
    }
}

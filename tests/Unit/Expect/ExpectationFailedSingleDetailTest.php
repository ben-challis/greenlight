<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Core\Result\FailureDetail;
use Greenlight\Core\Result\SourceLocation;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;

final class ExpectationFailedSingleDetailTest
{
    #[Test]
    public function oneDetailUsesTheSingularDiagnostic(): void
    {
        $detail = new FailureDetail(
            'Expected values to match.',
            location: new SourceLocation('/tests/ExampleTest.php', 12),
        );

        $failure = ExpectationFailed::fromDetails([$detail]);

        Expect::that($failure->getMessage())
            ->because('one detail MUST use the singular diagnostic')
            ->toBe('Expected values to match. (at /tests/ExampleTest.php:12)');
        Expect::that($failure->details)
            ->toBe([$detail]);
        Expect::that($failure->detail())
            ->toBe($detail);
    }
}

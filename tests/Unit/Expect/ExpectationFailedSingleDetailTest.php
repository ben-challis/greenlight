<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\SourceLocation;

use function Greenlight\expect;

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

        expect($failure->getMessage())
            ->because('one detail MUST use the singular diagnostic')
            ->toBe('Expected values to match. (at /tests/ExampleTest.php:12)');
        expect($failure->details)
            ->toBe([$detail]);
        expect($failure->detail())
            ->toBe($detail);
    }
}

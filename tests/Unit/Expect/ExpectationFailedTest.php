<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\SourceLocation;

use function Greenlight\expect;

final class ExpectationFailedTest
{
    #[Test]
    public function multipleDetailsProduceANumberedMessageWithLocations(): void
    {
        $first = new FailureDetail(
            'Expected the value to be ready.',
            location: new SourceLocation('/project/tests/ProbeTest.php', 12),
        );
        $second = new FailureDetail('Expected the callback to run.');

        $failure = ExpectationFailed::fromDetails([$first, $second]);

        expect($failure->getMessage())
            ->because('multiple details produce a numbered message with locations')
            ->toBe(
                "2 expectations failed:\n"
                . "1) Expected the value to be ready. (at /project/tests/ProbeTest.php:12)\n"
                . '2) Expected the callback to run.',
            );
        expect($failure->details)
            ->toBe([$first, $second]);
        expect($failure->detail())
            ->toBe($first);
    }
}

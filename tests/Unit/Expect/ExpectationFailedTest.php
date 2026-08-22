<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Expect\ExpectationFailed;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\SourceLocation;

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

        Expect::that($failure->getMessage())
            ->because('multiple details produce a numbered message with locations')
            ->toBe(
                "2 expectations failed:\n"
                . "1) Expected the value to be ready. (at /project/tests/ProbeTest.php:12)\n"
                . '2) Expected the callback to run.',
            );
        Expect::that($failure->details)
            ->toBe([$first, $second]);
        Expect::that($failure->detail())
            ->toBe($first);
    }
}

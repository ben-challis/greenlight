<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Result;

use Greenlight\Attribute\Test;
use Greenlight\Result\FailureDetail;
use Greenlight\Result\SourceLocation;

use function Greenlight\expect;

final readonly class FailureDetailWireContractTest
{
    #[Test]
    public function failureDetailKeepsItsExactWireValues(): void
    {
        $detail = new FailureDetail(
            'Values are not identical.',
            'expected value',
            'actual value',
            new SourceLocation('/project/tests/ExampleTest.php', 42),
        );

        expect($detail->toWire())
            ->because('a failure detail MUST keep its message, diff, and source location')
            ->toBe([
                'message' => 'Values are not identical.',
                'expected' => 'expected value',
                'actual' => 'actual value',
                'location' => [
                    'file' => '/project/tests/ExampleTest.php',
                    'line' => 42,
                ],
            ]);
    }
}

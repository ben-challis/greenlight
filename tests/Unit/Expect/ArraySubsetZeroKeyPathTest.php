<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Expect;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;

final readonly class ArraySubsetZeroKeyPathTest
{
    #[Test]
    public function nestedDifferencePathsRetainANumericZeroParentKey(): void
    {
        $detail = FailureProbe::detailOf(
            static fn() => Expect::value([0 => ['actual' => true]])
                ->toContainSubset([0 => ['missing' => true]]),
        );

        Expect::value($detail->message)
            ->because('nested subset diagnostics MUST retain a numeric zero parent key')
            ->toContain("(missing key '0.missing').");
    }
}

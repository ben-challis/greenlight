<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DataRowsDuplicateInline;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Fail;

final class DuplicateInlineRowKeyTest
{
    #[Test]
    #[DataRow([1], label: 'twice')]
    #[DataRow([2], label: 'twice')]
    public function probe(int $value): void
    {
        if ($value < 0) {
            Fail::because(\sprintf(
                'Expected the duplicate-inline-row fixture value to be non-negative, got %d.',
                $value,
            ));
        }
    }
}

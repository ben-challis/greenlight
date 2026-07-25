<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DataRowsConflict;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Fail;

final class DuplicateRowKeyTest
{
    #[Test]
    #[DataRow([1], label: 'twice')]
    #[DataSet('rows')]
    public function probe(int $value): void
    {
        if ($value < 0) {
            Fail::because(\sprintf(
                'Expected the duplicate-row fixture value to be non-negative, got %d.',
                $value,
            ));
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function rows(): iterable
    {
        yield 'twice' => [2];
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DataRowsConflict;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;

final class DuplicateRowKeyTest
{
    #[Test]
    #[DataRow([1], label: 'twice')]
    #[DataSet('rows')]
    public function probe(int $value): void
    {
        if ($value < 0) {
            throw new \RuntimeException('negative');
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

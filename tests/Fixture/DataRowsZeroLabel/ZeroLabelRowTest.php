<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DataRowsZeroLabel;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;

final class ZeroLabelRowTest
{
    #[Test]
    #[DataRow(['value'], label: '0')]
    public function accepts(string $value): void {}
}

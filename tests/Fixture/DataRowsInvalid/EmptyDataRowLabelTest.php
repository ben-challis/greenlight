<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DataRowsInvalid;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;

final class EmptyDataRowLabelTest
{
    #[Test]
    #[DataRow([], label: '')]
    public function neverDiscovered(): void {}
}

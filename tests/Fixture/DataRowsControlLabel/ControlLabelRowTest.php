<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\DataRowsControlLabel;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;

final readonly class ControlLabelRowTest
{
    #[Test]
    #[DataRow([], label: "line\n")]
    public function accepts(): void {}
}

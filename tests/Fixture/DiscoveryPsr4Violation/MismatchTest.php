<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\SomewhereElse;

use Greenlight\Attribute\Test;

final class MismatchTest
{
    #[Test]
    public function unreachable(): void
    {
        echo "mismatch\n";
    }
}

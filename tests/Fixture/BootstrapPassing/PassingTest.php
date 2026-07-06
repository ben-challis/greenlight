<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\BootstrapPassing;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Attribute\Test;

final class PassingTest
{
    #[Before]
    public function markBefore(): void
    {
        echo "marker:before\n";
    }

    #[Test]
    public function passes(): void
    {
        echo "marker:test\n";
    }

    #[After]
    public function markAfter(): void
    {
        echo "marker:after\n";
    }
}

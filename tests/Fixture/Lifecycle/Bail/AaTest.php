<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Bail;

use Greenlight\Attribute\Test;

final class AaTest
{
    #[Test]
    public function fails(): never
    {
        throw new \RuntimeException('first failure');
    }

    #[Test]
    public function wouldPass(): void {}
}

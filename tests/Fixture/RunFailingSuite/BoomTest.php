<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\RunFailingSuite;

use Greenlight\Attribute\Test;

final class BoomTest
{
    #[Test]
    public function passes(): void {}

    #[Test]
    public function explodes(): never
    {
        throw new \RuntimeException('intentional boom');
    }
}

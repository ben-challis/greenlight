<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\LeakSuite;

use Greenlight\Attribute\Test;

final class LeakyTest
{
    /**
     * @var list<object>
     */
    public static array $retained = [];

    #[Test]
    public function passesButLeaksItself(): void
    {
        self::$retained[] = $this;
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\Services;

use Greenlight\Attribute\Test;

final readonly class ServicesTest
{
    public function __construct(
        private ServiceProbe $probe,
    ) {}

    #[Test]
    public function firstTouch(): void
    {
        $this->probe->touch();
    }

    #[Test]
    public function secondTouch(): void
    {
        $this->probe->touch();
    }
}

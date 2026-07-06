<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

readonly class ReadonlyService
{
    public function __construct(
        public string $name,
    ) {}
}

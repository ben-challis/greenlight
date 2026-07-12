<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface CacheAlpha
{
    public function get(string $key): string;
}

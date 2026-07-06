<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface CacheBeta
{
    public function get(string $key, int $timeToLive): string;
}

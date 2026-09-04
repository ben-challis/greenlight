<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface ProxyCacheContract
{
    public function notify(): void;
}

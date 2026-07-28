<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface OptionalReference
{
    public function supplied(?string &$value = null): int;
}

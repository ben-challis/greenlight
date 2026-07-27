<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface ObjectDefault
{
    public function run(\stdClass $value = new \stdClass()): void;
}

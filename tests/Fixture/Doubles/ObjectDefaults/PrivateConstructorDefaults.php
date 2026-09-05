<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles\ObjectDefaults;

class PrivateConstructorDefaults
{
    private function __construct() {}

    public function run(self $value = new self()): void {}
}

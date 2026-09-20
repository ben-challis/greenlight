<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles\ObjectDefaults;

interface TrackedDefaults
{
    public function run(TrackedValue $value = new TrackedValue('default'), string $marker = ''): void;
}

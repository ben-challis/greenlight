<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Expect;

final class Holder
{
    public function __construct(public ?Holder $inner) {}
}

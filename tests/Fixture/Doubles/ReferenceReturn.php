<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface ReferenceReturn
{
    public function &value(): string;
}

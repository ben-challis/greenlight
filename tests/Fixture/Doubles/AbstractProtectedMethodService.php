<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

abstract class AbstractProtectedMethodService
{
    abstract protected function prefix(): string;
}

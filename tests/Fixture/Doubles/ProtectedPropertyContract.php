<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

abstract class ProtectedPropertyContract
{
    abstract protected string $status { get; set; }
}

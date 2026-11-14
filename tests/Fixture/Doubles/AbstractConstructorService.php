<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

abstract class AbstractConstructorService
{
    abstract public function __construct(string $name);

    abstract public function name(): string;
}

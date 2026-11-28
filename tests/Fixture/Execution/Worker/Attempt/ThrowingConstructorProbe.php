<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Worker\Attempt;

final class ThrowingConstructorProbe
{
    public function __construct()
    {
        throw new \RuntimeException('constructor failed');
    }

    public function neverRuns(): never
    {
        throw new \LogicException('The test method MUST NOT run.');
    }
}

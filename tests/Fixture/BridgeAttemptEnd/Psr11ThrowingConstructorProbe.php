<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\BridgeAttemptEnd;

final class Psr11ThrowingConstructorProbe
{
    public function __construct(AttemptCounter $counter)
    {
        $counter->value = 99;

        throw new \RuntimeException('constructor failed');
    }

    public function neverRuns(): never
    {
        throw new \LogicException('The test method MUST NOT run.');
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\BridgeAttemptEnd;

use Tempest\Container\Container;

final class TempestThrowingConstructorProbe
{
    public function __construct(Container $container)
    {
        $container->singleton(AttemptCounter::class, new AttemptCounter());

        throw new \RuntimeException('constructor failed');
    }

    public function neverRuns(): never
    {
        throw new \LogicException('The test method MUST NOT run.');
    }
}

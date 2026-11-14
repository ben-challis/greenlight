<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\BridgeAttemptEnd;

use Greenlight\Tests\Fixture\Symfony\VisitCounter;

final class SymfonyThrowingConstructorProbe
{
    public function __construct(VisitCounter $counter)
    {
        $counter->record();

        throw new \RuntimeException('constructor failed');
    }

    public function neverRuns(): never
    {
        throw new \LogicException('The test method MUST NOT run.');
    }
}

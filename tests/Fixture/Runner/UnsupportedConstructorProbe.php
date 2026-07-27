<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner;

final readonly class UnsupportedConstructorProbe
{
    public function __construct(public string $value) {}

    public function neverRuns(): never
    {
        throw new \LogicException('The unsupported-constructor probe MUST NOT run.');
    }
}

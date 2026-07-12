<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

/**
 * A deliberately tiny class for driver integration tests: add() is executed
 * during a collection window and its return line must show up as covered,
 * while never() stays uncovered.
 */
final class Adder
{
    public const int ADD_RETURN_LINE = 20;

    public const int NEVER_RETURN_LINE = 25;

    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function never(): int
    {
        return -1;
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

interface TypedArguments
{
    public function scale(float $factor): float;

    public function accept(mixed $value): void;

    /**
     * @param callable(): void $task
     */
    public function run(callable $task): void;
}

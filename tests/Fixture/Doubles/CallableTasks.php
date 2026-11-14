<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Doubles;

class CallableTasks
{
    /**
     * @param callable(): void $task
     */
    public function run(callable $task): bool
    {
        $task();

        return true;
    }

    protected function protectedTask(): void {}

    // @phpstan-ignore method.unused (Tests pass this method as a callable array.)
    private function privateTask(): void {}
}

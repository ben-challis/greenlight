<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Lifecycle\UnknownDep;

use Greenlight\Attribute\Test;

final readonly class UnknownDepTest
{
    /**
     * @param \SplStack<int> $stack
     */
    public function __construct(
        private \SplStack $stack,
    ) {}

    #[Test]
    public function neverRuns(): void
    {
        $this->stack->push(1);
    }
}

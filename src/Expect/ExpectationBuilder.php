<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Selects an explicit call subject after `Greenlight\expect()` with no argument.
 */
final readonly class ExpectationBuilder
{
    /**
     * Selects a call for later execution.
     *
     * @template T
     *
     * @param callable(): T $call
     *
     * @return CallExpectation<T>
     */
    public function calling(callable $call): CallExpectation
    {
        return Expect::calling($call);
    }
}

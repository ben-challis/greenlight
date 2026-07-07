<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * The planning DSL handed to the closures of Doubles::mock() and
 * Doubles::stub().
 *
 * Declare call patterns fluently:
 *
 *     $plan->expects('charge')->with(MockPlan::any())->once()->andReturns($ok);
 *
 * Cardinality defaults to at least once. On a mock every declared pattern is
 * verified at test end; on a stub the same plan only configures answers and
 * nothing is enforced.
 */
final readonly class MockPlan
{
    /**
     * @internal constructed by the Doubles factory only
     */
    public function __construct(
        private DoubleState $state,
    ) {}

    /**
     * Argument wildcard for with(): matches any value in that position.
     */
    public static function any(): Any
    {
        return new Any();
    }

    /**
     * @param non-empty-string $method
     */
    public function expects(string $method): MethodExpectation
    {
        $this->assertPlannable($method);

        $expectation = new MethodExpectation($method);
        $this->state->expectations[] = $expectation;

        return $expectation;
    }

    /**
     * @param non-empty-string $method
     */
    private function assertPlannable(string $method): void
    {
        $reflection = new \ReflectionClass($this->state->type);

        if (!$reflection->hasMethod($method)) {
            throw new DoublesError(\sprintf('%s has no method %s(), so it cannot be planned.', $this->state->type, $method));
        }

        $declared = $reflection->getMethod($method);

        if ($declared->isStatic()) {
            throw new DoublesError(\sprintf('%s::%s() is static; static methods cannot be doubled.', $this->state->type, $method));
        }

        if (!$declared->isPublic()) {
            throw new DoublesError(\sprintf('%s::%s() is not public, so it cannot be planned on a double.', $this->state->type, $method));
        }

        if ($declared->isFinal()) {
            throw new DoublesError(\sprintf('%s::%s() is final and cannot be intercepted. Double an interface instead.', $this->state->type, $method));
        }
    }
}

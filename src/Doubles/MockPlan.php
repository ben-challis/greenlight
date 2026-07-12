<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * The planning DSL handed to the closure of Doubles::mock().
 *
 * Declare call patterns fluently:
 *
 *     $plan->expects('charge')->with(MockPlan::any())->once()->andReturns($ok);
 *
 * Cardinality defaults to at least once. Every declared pattern is verified
 * when the test's scope closes.
 */
final readonly class MockPlan
{
    /**
     * @internal constructed by the Doubles factory only
     */
    public function __construct(private DoubleState $state) {}

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
            throw DoublesError::noSuchMethod($this->state->type, $method);
        }

        $declared = $reflection->getMethod($method);

        if ($declared->isStatic()) {
            throw DoublesError::staticMethod($this->state->type, $method);
        }

        if (!$declared->isPublic()) {
            throw DoublesError::methodNotPublic($this->state->type, $method);
        }

        if ($declared->isFinal()) {
            throw DoublesError::finalMethod($this->state->type, $method);
        }
    }
}

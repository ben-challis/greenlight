<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Defines the mock plan that Doubles::mock() supplies to its closure.
 *
 * Use the fluent methods to declare call patterns. The default cardinality is
 * at least one call. The verifier checks each declared pattern when the test
 * scope closes.
 */
final readonly class MockPlan
{
    /**
     * @internal Only the Doubles factory constructs this object.
     */
    public function __construct(private DoubleState $state) {}

    /**
     * Returns the with() wildcard that accepts all values in its position.
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

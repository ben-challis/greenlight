<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Defines the mock plan that `Doubles::mock()` supplies to its closure.
 *
 * Use the fluent methods to declare call patterns. The default cardinality is
 * at least one call. The verifier checks each declared pattern when the test
 * scope closes.
 *
 * @template TTarget of object = object
 */
final readonly class MockPlan
{
    /**
     * @internal Only the Doubles factory constructs this object.
     *
     * @param class-string<TTarget> $target
     */
    public function __construct(
        private DoubleState $state,
        private string $target,
    ) {}

    /**
     * Returns the `with()` wildcard that accepts all values in its position.
     */
    public static function any(): ArgumentMatcher
    {
        return new Any();
    }

    /**
     * @template TMethod of non-empty-string
     *
     * @param TMethod $method
     *
     * @return MethodExpectation<TTarget, TMethod>
     * @throws InvalidDoubleUsage
     */
    public function expects(string $method): MethodExpectation
    {
        $contract = $this->assertPlannable($method);

        $expectation = new MethodExpectation($contract);
        $this->state->expectations[] = $expectation;

        return $expectation;
    }

    /**
     * @template TMethod of non-empty-string
     *
     * @param TMethod $method
     *
     * @return MethodCallContract<TTarget, TMethod>
     * @throws InvalidDoubleUsage
     */
    private function assertPlannable(string $method): MethodCallContract
    {
        $reflection = new \ReflectionClass($this->target);

        if (!$reflection->hasMethod($method)) {
            throw InvalidDoubleUsage::noSuchMethod($this->state->type, $method);
        }

        $declared = $reflection->getMethod($method);

        if ($declared->isStatic()) {
            throw InvalidDoubleUsage::staticMethod($this->state->type, $method);
        }

        if (!$declared->isPublic()) {
            throw InvalidDoubleUsage::methodNotPublic($this->state->type, $method);
        }

        if ($declared->isFinal()) {
            throw InvalidDoubleUsage::finalMethod($this->state->type, $method);
        }

        return MethodCallContract::fromReflection($this->target, $method, $declared);
    }
}

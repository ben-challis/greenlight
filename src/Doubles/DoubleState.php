<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

use Greenlight\Core\Result\FailureDetail;

/**
 * Mutable per-double bookkeeping: the planned expectations, every recorded
 * call, and failures raised at call time. Deliberately holds no reference to
 * the proxy object, so keeping states for verification never keeps a double
 * alive.
 *
 * @internal
 */
final class DoubleState
{
    /**
     * @var list<MethodExpectation>
     */
    public array $expectations = [];

    /**
     * @var array<string, list<list<mixed>>>
     */
    public array $recordedCalls = [];

    /**
     * Call-time failures are thrown immediately and also kept here, so a
     * test that swallows the throw still fails at verification.
     *
     * @var list<FailureDetail>
     */
    public array $callFailures = [];

    /**
     * @param class-string $type
     */
    public function __construct(
        public readonly string $type,
        public readonly DoubleKind $kind,
    ) {}

    /**
     * @return list<MethodExpectation>
     */
    public function expectationsFor(string $method): array
    {
        return \array_values(\array_filter(
            $this->expectations,
            static fn(MethodExpectation $expectation): bool => $expectation->method === $method,
        ));
    }
}

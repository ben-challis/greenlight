<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

use Greenlight\Core\Result\FailureDetail;

/**
 * Contains the mutable state for one double. This state includes planned
 * expectations, recorded calls, and call failures.
 *
 * The state does not refer to the proxy object. Thus, the verification state
 * does not keep a double alive.
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
     * The call handler immediately throws call failures and also stores them
     * here. Thus, verification fails if a test catches the throwable.
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

<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/** @internal */
final readonly class PredicateMatcher implements TypedArgumentMatcher
{
    private ?ArgumentType $argumentType;

    /**
     * @param \Closure(mixed): mixed $predicate
     * @throws InvalidDoubleUsage
     */
    public function __construct(
        private \Closure $predicate,
        private string $description,
    ) {
        $reflection = new \ReflectionFunction($predicate);
        $parameter = $reflection->getParameters()[0] ?? null;
        $type = $parameter?->getType();
        $this->argumentType = $type === null
            ? null
            : ArgumentType::fromReflection($type, $reflection->getClosureScopeClass());
    }

    public function matches(mixed $value): bool
    {
        if ($this->argumentType?->accepts($value) === false) {
            return false;
        }

        return ($this->predicate)($value) === true;
    }

    public function describe(): string
    {
        return \sprintf('predicate(%s)', $this->description);
    }

    public function argumentType(): ?ArgumentType
    {
        return $this->argumentType;
    }
}

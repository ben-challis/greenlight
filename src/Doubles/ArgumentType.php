<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Represents the values that a typed argument matcher can accept.
 *
 * The alternatives use disjunctive normal form. Each inner list is an
 * intersection. The outer list is a union.
 *
 * @internal
 */
final readonly class ArgumentType
{
    /**
     * @param non-empty-list<non-empty-list<non-empty-string>> $alternatives
     */
    private function __construct(private array $alternatives) {}

    /**
     * @param \ReflectionClass<object>|null $context
     * @throws InvalidDoubleUsage
     */
    public static function fromReflection(\ReflectionType $type, ?\ReflectionClass $context = null): self
    {
        if ($type instanceof \ReflectionNamedType) {
            $name = self::reflectionName($type, $context);
            $result = new self([[$name]]);

            return $type->allowsNull() && !\in_array($name, ['mixed', 'null'], true)
                ? $result->union(new self([['null']]))
                : $result;
        }

        if ($type instanceof \ReflectionUnionType) {
            $members = \array_map(
                static fn(\ReflectionType $member): self => self::fromReflection($member, $context),
                $type->getTypes(),
            );

            return \array_reduce(
                \array_slice($members, 1),
                static fn(self $combined, self $member): self => $combined->union($member),
                $members[0],
            );
        }

        if ($type instanceof \ReflectionIntersectionType) {
            $members = \array_map(
                static fn(\ReflectionType $member): self => self::fromReflection($member, $context),
                $type->getTypes(),
            );

            return \array_reduce(
                \array_slice($members, 1),
                static fn(self $combined, self $member): self => $combined->intersect($member),
                $members[0],
            );
        }

        throw InvalidDoubleUsage::unsupportedReflectionType($type::class);
    }

    public static function fromTypeName(string $type): ?self
    {
        $type = \ltrim($type, '\\');

        if (\in_array($type, ['array', 'bool', 'float', 'int', 'null', 'string'], true)
            || \class_exists($type)
            || \interface_exists($type)
            || \enum_exists($type)
        ) {
            return new self([[$type]]);
        }

        return null;
    }

    /**
     * Gets a known upper bound for values that have all the specified types.
     *
     * @param non-empty-list<string> $types
     */
    public static function fromIntersectionTypeNames(array $types): ?self
    {
        $known = \array_values(\array_filter(\array_map(self::fromTypeName(...), $types)));

        if ($known === []) {
            return null;
        }

        return \array_reduce(
            \array_slice($known, 1),
            static fn(self $combined, self $member): self => $combined->intersect($member),
            $known[0],
        );
    }

    /**
     * Gets the values that have one or more of the specified known types.
     *
     * @param non-empty-list<string> $types
     */
    public static function fromUnionTypeNames(array $types): ?self
    {
        $known = \array_map(self::fromTypeName(...), $types);

        if (\in_array(null, $known, true)) {
            return null;
        }

        /** @var non-empty-list<self> $known */
        return \array_reduce(
            \array_slice($known, 1),
            static fn(self $combined, self $member): self => $combined->union($member),
            $known[0],
        );
    }

    public function intersect(self $other): self
    {
        $alternatives = [];

        foreach ($this->alternatives as $left) {
            foreach ($other->alternatives as $right) {
                $alternatives[] = \array_values(\array_unique([...$left, ...$right]));
            }
        }

        return new self($alternatives);
    }

    public function accepts(mixed $value): bool
    {
        return \array_any(
            $this->alternatives,
            static fn(array $alternative): bool => \array_all(
                $alternative,
                static fn(string $type): bool => self::acceptsNamed($value, $type),
            ),
        );
    }

    public function overlaps(self $other): bool
    {
        foreach ($this->alternatives as $left) {
            foreach ($other->alternatives as $right) {
                if ($this->possible([...$left, ...$right])) {
                    return true;
                }
            }
        }

        return false;
    }

    public function describe(): string
    {
        $union = \count($this->alternatives) > 1;

        return \implode('|', \array_map(
            static function (array $alternative) use ($union): string {
                $intersection = \implode('&', $alternative);

                return $union && \count($alternative) > 1 ? '(' . $intersection . ')' : $intersection;
            },
            $this->alternatives,
        ));
    }

    private function union(self $other): self
    {
        return new self([...$this->alternatives, ...$other->alternatives]);
    }

    /**
     * @param \ReflectionClass<object>|null $context
     * @return non-empty-string
     * @throws InvalidDoubleUsage
     */
    private static function reflectionName(\ReflectionNamedType $type, ?\ReflectionClass $context): string
    {
        $name = $type->getName();

        if ($name === '') {
            throw InvalidDoubleUsage::unsupportedReflectionType($type::class);
        }

        if ($type->isBuiltin()) {
            return $name;
        }

        if ($name === 'self' || $name === 'static') {
            return !$context instanceof \ReflectionClass ? $name : $context->name;
        }

        if ($name !== 'parent') {
            return $name;
        }

        if (!$context instanceof \ReflectionClass) {
            throw InvalidDoubleUsage::parentTypeWithoutParent('Closure');
        }

        $parent = $context->getParentClass();

        if ($parent === false) {
            throw InvalidDoubleUsage::parentTypeWithoutParent($context->name);
        }

        return $parent->name;
    }

    private static function acceptsNamed(mixed $value, string $type): bool
    {
        return match ($type) {
            'mixed' => true,
            'null' => $value === null,
            'true' => $value === true,
            'false' => $value === false,
            'bool' => \is_bool($value),
            'int' => \is_int($value),
            'float' => \is_float($value),
            'string' => \is_string($value),
            'array' => \is_array($value),
            'object' => \is_object($value),
            'callable' => \is_callable($value),
            'iterable' => \is_iterable($value),
            default => $value instanceof $type,
        };
    }

    /** @param non-empty-list<non-empty-string> $types */
    private function possible(array $types): bool
    {
        foreach ($types as $leftIndex => $left) {
            foreach (\array_slice($types, $leftIndex + 1) as $right) {
                if (!$this->namedTypesOverlap($left, $right)) {
                    return false;
                }
            }
        }

        return true;
    }

    private function namedTypesOverlap(string $left, string $right): bool
    {
        if ($left === $right || $left === 'mixed' || $right === 'mixed') {
            return true;
        }

        if (($left === 'bool' && \in_array($right, ['true', 'false'], true))
            || ($right === 'bool' && \in_array($left, ['true', 'false'], true))
        ) {
            return true;
        }

        if ($this->isClassType($left) && $this->isClassType($right)) {
            return $this->classTypesOverlap($left, $right);
        }

        if (($left === 'object' && $this->isClassType($right))
            || ($right === 'object' && $this->isClassType($left))
        ) {
            return true;
        }

        if ($left === 'callable' && $this->isClassType($right)) {
            return $this->classCanBeCallable($right);
        }

        if ($right === 'callable' && $this->isClassType($left)) {
            return $this->classCanBeCallable($left);
        }

        if ($left === 'iterable' && $this->isClassType($right)) {
            return $this->classCanBeIterable($right);
        }

        if ($right === 'iterable' && $this->isClassType($left)) {
            return $this->classCanBeIterable($left);
        }

        $pair = [$left, $right];

        return (\in_array('callable', $pair, true)
                && \array_any(['string', 'array', 'object', 'iterable'], static fn(string $type): bool => \in_array($type, $pair, true)))
            || (\in_array('iterable', $pair, true)
                && \array_any(['array', 'object', 'callable'], static fn(string $type): bool => \in_array($type, $pair, true)));
    }

    private function isClassType(string $type): bool
    {
        return !\in_array($type, [
            'mixed',
            'null',
            'true',
            'false',
            'bool',
            'int',
            'float',
            'string',
            'array',
            'object',
            'callable',
            'iterable',
        ], true);
    }

    private function classTypesOverlap(string $left, string $right): bool
    {
        $leftReflection = $this->classReflection($left);
        $rightReflection = $this->classReflection($right);

        if (!$leftReflection instanceof \ReflectionClass || !$rightReflection instanceof \ReflectionClass) {
            return true;
        }

        if ($leftReflection->isSubclassOf($right) || $rightReflection->isSubclassOf($left)) {
            return true;
        }

        if ($leftReflection->isInterface() && $rightReflection->isInterface()) {
            return true;
        }

        if ($leftReflection->isInterface()) {
            return !$rightReflection->isFinal();
        }

        if ($rightReflection->isInterface()) {
            return !$leftReflection->isFinal();
        }

        return false;
    }

    private function classCanBeCallable(string $type): bool
    {
        $reflection = $this->classReflection($type);

        if (!$reflection instanceof \ReflectionClass || $reflection->isInterface()) {
            return true;
        }

        return $reflection->hasMethod('__invoke') || !$reflection->isFinal();
    }

    private function classCanBeIterable(string $type): bool
    {
        $reflection = $this->classReflection($type);

        if (!$reflection instanceof \ReflectionClass || $reflection->isInterface()) {
            return true;
        }

        return $reflection->isSubclassOf(\Traversable::class) || !$reflection->isFinal();
    }

    /** @return \ReflectionClass<object>|null */
    private function classReflection(string $type): ?\ReflectionClass
    {
        if (!\class_exists($type) && !\interface_exists($type) && !\enum_exists($type)) {
            return null;
        }

        return new \ReflectionClass($type);
    }
}

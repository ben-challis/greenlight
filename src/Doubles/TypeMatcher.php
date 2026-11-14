<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/** @internal */
final readonly class TypeMatcher implements TypedArgumentMatcher
{
    private ?ArgumentType $argumentType;

    /**
     * @throws InvalidDoubleUsage
     */
    public function __construct(private string $type)
    {
        if (\trim($type) === '') {
            throw InvalidDoubleUsage::invalidArgumentType();
        }

        self::assertKnown('type', $type);

        $this->argumentType = ArgumentType::fromTypeName($type);
    }

    public function matches(mixed $value): bool
    {
        return self::matchesType($value, $this->type);
    }

    public function describe(): string
    {
        return \sprintf('type(%s)', $this->type);
    }

    public function argumentType(): ?ArgumentType
    {
        return $this->argumentType;
    }

    public static function matchesType(mixed $value, string $type): bool
    {
        return ArgumentType::fromTypeName($type)?->accepts($value) ?? \get_debug_type($value) === $type;
    }

    /**
     * Accepts a built-in type name, a loadable class, interface, or enum, and
     * a resource name from `get_debug_type()`. A matcher for another name
     * cannot accept a value.
     *
     * @throws InvalidDoubleUsage
     */
    public static function assertKnown(string $factory, string $type): void
    {
        if (ArgumentType::fromTypeName($type) instanceof ArgumentType
            || \preg_match('/^resource \([^()]+\)$/', $type) === 1
        ) {
            return;
        }

        throw InvalidDoubleUsage::unknownArgumentType($factory, $type);
    }
}

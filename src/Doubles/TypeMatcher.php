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
        return $value instanceof $type || \get_debug_type($value) === $type;
    }
}

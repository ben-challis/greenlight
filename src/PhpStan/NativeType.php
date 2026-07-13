<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Attribute\CoverageIgnore;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\CallableType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\IterableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Maps a native reflection type from a matcher closure onto the PHPStan type
 * it advertises.
 *
 * Matchers declare native parameter types only (closures carry no analysable
 * phpdoc at runtime), so this covers exactly the native type grammar.
 *
 * @internal
 */
final class NativeType
{
    #[CoverageIgnore]
    private function __construct() {}

    public static function fromReflection(?\ReflectionType $type): Type
    {
        if ($type instanceof \ReflectionUnionType) {
            return TypeCombinator::union(...\array_map(self::fromReflection(...), $type->getTypes()));
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return new IntersectionType(\array_values(\array_map(self::fromReflection(...), $type->getTypes())));
        }

        if (!$type instanceof \ReflectionNamedType) {
            return new MixedType();
        }

        $mapped = self::fromName($type->getName());

        if ($type->allowsNull() && !\in_array($type->getName(), ['mixed', 'null'], true)) {
            return TypeCombinator::addNull($mapped);
        }

        return $mapped;
    }

    private static function fromName(string $name): Type
    {
        return match ($name) {
            'string' => new StringType(),
            'int' => new IntegerType(),
            'float' => new FloatType(),
            'bool' => new BooleanType(),
            'array' => new ArrayType(new MixedType(), new MixedType()),
            'iterable' => new IterableType(new MixedType(), new MixedType()),
            'callable' => new CallableType(),
            'object' => new ObjectWithoutClassType(),
            'null' => new NullType(),
            'mixed' => new MixedType(),
            default => new ObjectType($name),
        };
    }
}

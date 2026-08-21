<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Attribute\CoverageIgnore;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\CallableType;
use PHPStan\Type\Constant\ConstantBooleanType;
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
 * Matcher closures have no PHPDoc that PHPStan can analyze at run time. Thus,
 * this class supports only native types.
 *
 * @internal
 */
final class NativeType
{
    #[CoverageIgnore]
    private function __construct() {}

    /** @param ?\ReflectionClass<object> $scopeClass */
    public static function fromReflection(?\ReflectionType $type, ?\ReflectionClass $scopeClass = null): Type
    {
        if ($type instanceof \ReflectionUnionType) {
            return TypeCombinator::union(...\array_map(
                static fn(\ReflectionType $member): Type => self::fromReflection($member, $scopeClass),
                $type->getTypes(),
            ));
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return new IntersectionType(\array_values(\array_map(
                static fn(\ReflectionType $member): Type => self::fromReflection($member, $scopeClass),
                $type->getTypes(),
            )));
        }

        if (!$type instanceof \ReflectionNamedType) {
            return new MixedType();
        }

        $mapped = self::fromName($type->getName(), $scopeClass);

        if ($type->allowsNull() && !\in_array($type->getName(), ['mixed', 'null'], true)) {
            return TypeCombinator::addNull($mapped);
        }

        return $mapped;
    }

    public static function fromParameter(\ReflectionParameter $parameter): Type
    {
        return self::fromReflection(
            $parameter->getType(),
            self::scopeClass($parameter->getDeclaringFunction()),
        );
    }

    /** @param ?\ReflectionClass<object> $scopeClass */
    private static function fromName(string $name, ?\ReflectionClass $scopeClass): Type
    {
        return match ($name) {
            'string' => new StringType(),
            'int' => new IntegerType(),
            'float' => new FloatType(),
            'bool' => new BooleanType(),
            'true' => new ConstantBooleanType(true),
            'false' => new ConstantBooleanType(false),
            'array' => new ArrayType(new MixedType(), new MixedType()),
            'iterable' => new IterableType(new MixedType(), new MixedType()),
            'callable' => new CallableType(),
            'object' => new ObjectWithoutClassType(),
            'null' => new NullType(),
            'mixed' => new MixedType(),
            'self', 'static' => $scopeClass instanceof \ReflectionClass
                ? new ObjectType($scopeClass->getName())
                : new MixedType(),
            'parent' => self::parentType($scopeClass),
            default => new ObjectType($name),
        };
    }

    /** @return ?\ReflectionClass<object> */
    private static function scopeClass(\ReflectionFunctionAbstract $function): ?\ReflectionClass
    {
        if ($function instanceof \ReflectionMethod) {
            return $function->getDeclaringClass();
        }

        return $function->getClosureScopeClass();
    }

    /** @param ?\ReflectionClass<object> $scopeClass */
    private static function parentType(?\ReflectionClass $scopeClass): Type
    {
        $parent = $scopeClass?->getParentClass();

        return $parent instanceof \ReflectionClass
            ? new ObjectType($parent->getName())
            : new MixedType();
    }
}

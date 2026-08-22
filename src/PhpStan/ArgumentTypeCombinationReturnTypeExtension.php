<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Doubles\Argument;
use Greenlight\Doubles\ArgumentMatcher;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\StaticCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicStaticMethodReturnTypeExtension;
use PHPStan\Type\FloatType;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Gets the combined value type for argument type matchers.
 *
 * @internal
 */
final class ArgumentTypeCombinationReturnTypeExtension implements DynamicStaticMethodReturnTypeExtension
{
    #[\Override]
    public function getClass(): string
    {
        return Argument::class;
    }

    #[\Override]
    public function isStaticMethodSupported(MethodReflection $methodReflection): bool
    {
        return \in_array($methodReflection->getName(), ['intersection', 'union'], true);
    }

    #[\Override]
    public function getTypeFromStaticMethodCall(
        MethodReflection $methodReflection,
        StaticCall $methodCall,
        Scope $scope,
    ): ?Type {
        if (\array_any($methodCall->getArgs(), static fn(Arg $argument): bool => $argument->unpack)) {
            return null;
        }

        $types = \array_map(
            fn(Arg $argument): Type => $this->valueType($scope->getType($argument->value)),
            $methodCall->getArgs(),
        );
        $combined = $methodReflection->getName() === 'intersection'
            ? TypeCombinator::intersect(...$types)
            : TypeCombinator::union(...$types);

        return new GenericObjectType(ArgumentMatcher::class, [$combined]);
    }

    private function valueType(Type $argument): Type
    {
        $strings = $argument->getConstantStrings();

        if ($strings !== []) {
            return TypeCombinator::union(...\array_map($this->constantValueType(...), $strings));
        }

        return $argument->isClassString()->yes()
            ? $argument->getClassStringObjectType()
            : new MixedType();
    }

    private function constantValueType(ConstantStringType $type): Type
    {
        return match ($type->getValue()) {
            'array' => new ArrayType(new MixedType(), new MixedType()),
            'bool' => new BooleanType(),
            'float' => new FloatType(),
            'int' => new IntegerType(),
            'null' => new NullType(),
            'string' => new StringType(),
            default => $type->isClassString()->yes()
                ? $type->getClassStringObjectType()
                : new MixedType(),
        };
    }
}

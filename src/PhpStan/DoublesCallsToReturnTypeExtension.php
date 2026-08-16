<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Doubles\Doubles;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\Constant\ConstantArrayTypeBuilder;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\IntegerType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Gets the recorded argument types for a constant `callsTo()` method name.
 *
 * @internal
 */
final readonly class DoublesCallsToReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    #[\Override]
    public function getClass(): string
    {
        return Doubles::class;
    }

    #[\Override]
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'callsTo';
    }

    #[\Override]
    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        $double = $this->argument($methodCall, 'double', 0);
        $method = $this->argument($methodCall, 'method', 1);

        if (!$double instanceof Arg || !$method instanceof Arg) {
            return null;
        }

        $doubleType = $scope->getType($double->value);
        $methodNames = $scope->getType($method->value)->getConstantStrings();

        if (\count($methodNames) !== 1) {
            return null;
        }

        $methodName = $methodNames[0]->getValue();

        if (!$doubleType->hasMethod($methodName)->yes()) {
            return null;
        }

        $argumentTypes = [];

        foreach ($doubleType->getMethod($methodName, $scope)->getVariants() as $variant) {
            $builder = ConstantArrayTypeBuilder::createEmpty();

            foreach ($variant->getParameters() as $parameter) {
                if ($parameter->isVariadic()) {
                    $builder->makeUnsealed(new IntegerType(), $parameter->getType());

                    continue;
                }

                $builder->setOffsetValueType(
                    null,
                    $parameter->getType(),
                    $parameter->isOptional(),
                );
            }

            $argumentTypes[] = $builder->getArray();
        }

        if ($argumentTypes === []) {
            return null;
        }

        $calls = new ArrayType(
            new IntegerType(),
            TypeCombinator::union(...$argumentTypes),
        );

        return TypeCombinator::intersect($calls, new AccessoryArrayListType());
    }

    private function argument(MethodCall $call, string $name, int $position): ?Arg
    {
        $nextPosition = 0;

        foreach ($call->getArgs() as $argument) {
            if ($argument->unpack) {
                continue;
            }

            if ($argument->name instanceof Identifier) {
                if ($argument->name->toString() === $name) {
                    return $argument;
                }

                continue;
            }

            if ($nextPosition === $position) {
                return $argument;
            }

            ++$nextPosition;
        }

        return null;
    }
}

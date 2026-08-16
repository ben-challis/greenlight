<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Doubles\ArgumentCaptor;
use Greenlight\Doubles\MethodExpectation;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Gets the selected method parameter type for `captureArgument()`.
 *
 * @internal
 */
final readonly class CaptureArgumentReturnTypeExtension implements DynamicMethodReturnTypeExtension
{
    public function __construct(private ReflectionProvider $reflectionProvider) {}

    #[\Override]
    public function getClass(): string
    {
        return MethodExpectation::class;
    }

    #[\Override]
    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'captureArgument';
    }

    #[\Override]
    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        $receiver = $scope->getType($methodCall->var);

        if (!new ObjectType(MethodExpectation::class)->isSuperTypeOf($receiver)->yes()) {
            return null;
        }

        $targets = $receiver->getTemplateType(MethodExpectation::class, 'TTarget')->getObjectClassNames();
        $methods = $receiver->getTemplateType(MethodExpectation::class, 'TMethod')->getConstantStrings();

        if (\count($targets) !== 1
            || \count($methods) !== 1
            || !$this->reflectionProvider->hasClass($targets[0])
        ) {
            return null;
        }

        $target = $this->reflectionProvider->getClass($targets[0]);
        $method = $methods[0]->getValue();

        if (!$target->hasNativeMethod($method)) {
            return null;
        }

        $variants = $target->getNativeMethod($method)->getVariants();

        if (\count($variants) !== 1) {
            return null;
        }

        $position = $this->position($methodCall, $scope);
        $parameters = $variants[0]->getParameters();

        if ($position === null || $position < 0 || $parameters === []) {
            return null;
        }

        $last = \array_key_last($parameters);

        if ($position > $last && !$parameters[$last]->isVariadic()) {
            return null;
        }

        $parameter = $parameters[\min($position, $last)];

        return new GenericObjectType(ArgumentCaptor::class, [$parameter->getType()]);
    }

    private function position(MethodCall $call, Scope $scope): ?int
    {
        $argument = $this->argument($call, 'position', 0);

        if (!$argument instanceof Arg) {
            return 0;
        }

        $values = \array_values(\array_filter(
            $scope->getType($argument->value)->getConstantScalarValues(),
            \is_int(...),
        ));

        return \count($values) === 1 ? $values[0] : null;
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

<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Expect\Expectation;
use Greenlight\Expect\ExpectationBuilder;
use PhpParser\Node\Expr\FuncCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Type\DynamicFunctionReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Distinguishes an omitted helper argument from a supplied broad subject type.
 * A conditional PHPDoc type alone cannot exclude the internal sentinel from mixed.
 *
 * @internal
 */
final readonly class ExpectFunctionReturnTypeExtension implements DynamicFunctionReturnTypeExtension
{
    #[\Override]
    public function isFunctionSupported(FunctionReflection $functionReflection): bool
    {
        return \strtolower($functionReflection->getName()) === 'greenlight\\expect';
    }

    #[\Override]
    public function getTypeFromFunctionCall(FunctionReflection $functionReflection, FuncCall $functionCall, Scope $scope): ?Type
    {
        $arguments = $functionCall->getArgs();

        if ($arguments === []) {
            return new ObjectType(ExpectationBuilder::class);
        }

        if (\count($arguments) !== 1 || $arguments[0]->unpack) {
            return null;
        }

        return new GenericObjectType(Expectation::class, [$scope->getType($arguments[0]->value)]);
    }
}

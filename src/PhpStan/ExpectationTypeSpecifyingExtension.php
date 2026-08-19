<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Expect\Expect;
use Greenlight\Expect\Expectation;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\CallableType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IterableType;
use PHPStan\Type\MethodTypeSpecifyingExtension;
use PHPStan\Type\MixedType;
use PHPStan\Type\NullType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;

/**
 * Narrows the subject after a synchronous type expectation passes.
 *
 * The call must contain `Expect::that()` in the same expression. This
 * constraint keeps the original subject expression available to PHPStan.
 *
 * @internal
 */
final class ExpectationTypeSpecifyingExtension implements MethodTypeSpecifyingExtension, TypeSpecifierAwareExtension
{
    private TypeSpecifier $typeSpecifier;

    #[\Override]
    public function getClass(): string
    {
        return Expectation::class;
    }

    #[\Override]
    public function isMethodSupported(
        MethodReflection $methodReflection,
        MethodCall $node,
        TypeSpecifierContext $context,
    ): bool {
        return $context->null() && \in_array($methodReflection->getName(), [
            'toBeInstanceOf',
            'toBeTrue',
            'toBeFalse',
            'toBeNull',
            'toBeArray',
            'toBeString',
            'toBeInt',
            'toBeFloat',
            'toBeBool',
            'toBeCallable',
            'toBeIterable',
        ], true);
    }

    #[\Override]
    public function specifyTypes(
        MethodReflection $methodReflection,
        MethodCall $node,
        Scope $scope,
        TypeSpecifierContext $context,
    ): SpecifiedTypes {
        $subject = $this->subject($node, $scope);
        $asserted = $this->assertedType($methodReflection->getName(), $node, $scope);
        $negated = $this->isNegated($node);

        if (!$subject instanceof Expr || !$asserted instanceof Type || $negated === null) {
            return new SpecifiedTypes();
        }

        return $this->typeSpecifier->create(
            $subject,
            $asserted,
            $negated ? TypeSpecifierContext::createFalse() : TypeSpecifierContext::createTrue(),
            $scope,
        );
    }

    #[\Override]
    public function setTypeSpecifier(TypeSpecifier $typeSpecifier): void
    {
        $this->typeSpecifier = $typeSpecifier;
    }

    private function subject(MethodCall $call, Scope $scope): ?Expr
    {
        $receiver = $call->var;

        while ($receiver instanceof MethodCall) {
            $receiver = $receiver->var;
        }

        if (!$receiver instanceof StaticCall
            || !$receiver->class instanceof Name
            || !$receiver->name instanceof Identifier
            || $scope->resolveName($receiver->class) !== Expect::class
            || $receiver->name->toString() !== 'that'
        ) {
            return null;
        }

        $argument = $receiver->getArgs()[0] ?? null;

        return $argument instanceof Arg ? $argument->value : null;
    }

    private function assertedType(string $matcher, MethodCall $call, Scope $scope): ?Type
    {
        $mixed = new MixedType();

        return match ($matcher) {
            'toBeInstanceOf' => isset($call->getArgs()[0])
                ? $scope->getType($call->getArgs()[0]->value)->getClassStringObjectType()
                : null,
            'toBeTrue' => new ConstantBooleanType(true),
            'toBeFalse' => new ConstantBooleanType(false),
            'toBeNull' => new NullType(),
            'toBeArray' => new ArrayType($mixed, $mixed),
            'toBeString' => new StringType(),
            'toBeInt' => new IntegerType(),
            'toBeFloat' => new FloatType(),
            'toBeBool' => new BooleanType(),
            'toBeCallable' => new CallableType(),
            'toBeIterable' => new IterableType($mixed, $mixed),
            default => null,
        };
    }

    private function isNegated(MethodCall $call): ?bool
    {
        $receiver = $call->var;

        while ($receiver instanceof MethodCall) {
            if (!$receiver->name instanceof Identifier) {
                return null;
            }

            $method = $receiver->name->toString();

            if ($method === 'not') {
                return true;
            }

            if ($method !== 'because') {
                break;
            }

            $receiver = $receiver->var;
        }

        return false;
    }
}

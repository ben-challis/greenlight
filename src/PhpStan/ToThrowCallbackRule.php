<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Expect\ConsistentlyExpectation;
use Greenlight\Expect\EventuallyExpectation;
use Greenlight\Expect\Expectation;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\Callables\CallableParametersAcceptor;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ClosureType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;

/**
 * Checks throwable callback constraints that the generic signature cannot express.
 *
 * @internal
 *
 * @implements Rule<MethodCall>
 */
final class ToThrowCallbackRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->name->toString() !== 'toThrow') {
            return [];
        }

        $receiver = $scope->getType($node->var);
        $supported = \array_any(
            [Expectation::class, EventuallyExpectation::class, ConsistentlyExpectation::class],
            static fn(string $class): bool => new ObjectType($class)->isSuperTypeOf($receiver)->yes(),
        );

        if (!$supported) {
            return [];
        }

        $argument = $this->throwableArgument($node);

        if (!$argument instanceof Arg) {
            return [];
        }

        $type = $scope->getType($argument->value);

        if (!$type instanceof ClosureType) {
            return [];
        }

        foreach ($type->getCallableParametersAcceptors($scope) as $acceptor) {
            $error = $this->callbackError($acceptor, $node->getStartLine());

            if ($error instanceof IdentifierRuleError) {
                return [$error];
            }
        }

        return [];
    }

    private function throwableArgument(MethodCall $call): ?Arg
    {
        foreach ($call->getArgs() as $argument) {
            if ($argument->unpack) {
                continue;
            }

            if ($argument->name instanceof Identifier) {
                if ($argument->name->toString() === 'throwable') {
                    return $argument;
                }

                continue;
            }

            return $argument;
        }

        return null;
    }

    private function callbackError(CallableParametersAcceptor $callback, int $line): ?IdentifierRuleError
    {
        $parameters = $callback->getParameters();
        $required = \array_filter(
            $parameters,
            static fn(ParameterReflection $parameter): bool => !$parameter->isOptional() && !$parameter->isVariadic(),
        );

        if ($parameters === [] || $parameters[0]->isVariadic()) {
            return $this->error(
                'The throwable callback for toThrow() MUST accept one typed Throwable argument.',
                $line,
            );
        }

        if (\count($required) > 1) {
            return null;
        }

        if ($parameters[0]->passedByReference()->yes()) {
            return $this->error(
                'The throwable callback for toThrow() MUST accept its argument by value.',
                $line,
            );
        }

        $parameterType = $parameters[0]->getType();
        $classNames = $parameterType->getObjectClassNames();
        $throwable = new ObjectType(\Throwable::class);

        if ($parameterType->isObject()->no()
            || (\count($classNames) === 1
                && !$throwable->isSuperTypeOf(new ObjectType($classNames[0]))->yes())
        ) {
            return null;
        }

        if (\count($classNames) !== 1
            || !$throwable->isSuperTypeOf($parameterType)->yes()
        ) {
            return $this->error(\sprintf(
                'The throwable callback for toThrow() MUST declare one named, non-null Throwable parameter type. Its parameter type is %s.',
                $parameterType->describe(VerbosityLevel::typeOnly()),
            ), $line);
        }

        return null;
    }

    private function error(string $message, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('greenlight.toThrow.callback')
            ->line($line)
            ->build();
    }
}

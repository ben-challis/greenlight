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
use PHPStan\Type\ObjectType;

/**
 * Checks the argument count and reference mode of a `toThrow()` matching
 * callback. The method PHPDoc checks its parameter and return types.
 *
 * @internal
 *
 * @implements Rule<MethodCall>
 */
final class ToThrowMatchingCallbackRule implements Rule
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

        $argument = $this->matchingArgument($node);

        if (!$argument instanceof Arg) {
            return [];
        }

        $type = $scope->getType($argument->value);

        if (!new ObjectType(\Closure::class)->isSuperTypeOf($type)->yes()) {
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

    private function matchingArgument(MethodCall $call): ?Arg
    {
        $position = 0;

        foreach ($call->getArgs() as $argument) {
            if ($argument->unpack) {
                continue;
            }

            if ($argument->name instanceof Identifier) {
                if ($argument->name->toString() === 'matching') {
                    return $argument;
                }

                continue;
            }

            if ($position === 1) {
                return $argument;
            }

            ++$position;
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

        if ($parameters === [] || \count($required) > 1) {
            return $this->error(
                'The matching callback for toThrow() must accept one throwable argument.',
                $line,
            );
        }

        if ($parameters[0]->passedByReference()->yes()) {
            return $this->error(
                'The matching callback for toThrow() must accept its throwable argument by value.',
                $line,
            );
        }

        return null;
    }

    private function error(string $message, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('greenlight.toThrow.matchingCallback')
            ->line($line)
            ->build();
    }
}

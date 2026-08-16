<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Doubles\ArgumentMatcher;
use Greenlight\Doubles\MethodExpectation;
use Greenlight\Doubles\MockPlan;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

/**
 * Checks mock plan methods and their argument constraints.
 *
 * @internal
 *
 * @implements Rule<MethodCall>
 */
final readonly class DoublePlanRule implements Rule
{
    public function __construct(private ReflectionProvider $reflectionProvider) {}

    #[\Override]
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        return match ($node->name->toString()) {
            'expects' => $this->expectsErrors($node, $scope),
            'with', 'withNoArguments' => $this->argumentErrors($node, $scope),
            default => [],
        };
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function expectsErrors(MethodCall $call, Scope $scope): array
    {
        $receiver = $scope->getType($call->var);

        if (!new ObjectType(MockPlan::class)->isSuperTypeOf($receiver)->yes()) {
            return [];
        }

        $target = $this->targetClass($receiver, MockPlan::class, 'TTarget');
        $methodArgument = $call->getArgs()[0] ?? null;

        if (!$target instanceof ClassReflection || !$methodArgument instanceof Node\Arg) {
            return [];
        }

        $methodNames = $scope->getType($methodArgument->value)->getConstantStrings();

        if (\count($methodNames) !== 1) {
            return [];
        }

        $method = $methodNames[0]->getValue();

        if (!$target->hasNativeMethod($method)) {
            return [$this->error(
                \sprintf('Mock plan method %s::%s() does not exist.', $target->getDisplayName(), $method),
                'method',
                $call->getStartLine(),
            )];
        }

        $reflection = $target->getNativeMethod($method);

        if (!$reflection->isPublic() || $reflection->isStatic() || $this->isFinal($reflection)) {
            return [$this->error(
                \sprintf('Mock plan method %s::%s() must be public, non-static, and non-final.', $target->getDisplayName(), $method),
                'method',
                $call->getStartLine(),
            )];
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function argumentErrors(MethodCall $call, Scope $scope): array
    {
        $receiver = $scope->getType($call->var);

        if (!new ObjectType(MethodExpectation::class)->isSuperTypeOf($receiver)->yes()) {
            return [];
        }

        $target = $this->targetClass($receiver, MethodExpectation::class, 'TTarget');
        $methodType = $receiver->getTemplateType(MethodExpectation::class, 'TMethod');
        $methodNames = $methodType->getConstantStrings();

        if (!$target instanceof ClassReflection || \count($methodNames) !== 1) {
            return [];
        }

        $method = $methodNames[0]->getValue();

        if (!$target->hasNativeMethod($method)) {
            return [];
        }

        $acceptor = $this->singleAcceptor($target->getNativeMethod($method)->getVariants());

        if (!$acceptor instanceof ParametersAcceptor || \array_any($call->getArgs(), static fn(Node\Arg $argument): bool => $argument->unpack)) {
            return [];
        }

        $arguments = $call->getArgs();
        $selector = $call->name instanceof Identifier ? $call->name->toString() : '';

        if (($selector === 'with' && $arguments === [])
            || ($selector === 'withNoArguments' && $arguments !== [])
        ) {
            return [];
        }

        $parameters = $acceptor->getParameters();
        $required = \count(\array_filter(
            $parameters,
            static fn(ParameterReflection $parameter): bool => !$parameter->isOptional() && !$parameter->isVariadic(),
        ));
        $variadic = $parameters !== [] && $parameters[\array_key_last($parameters)]->isVariadic();
        $actual = \count($arguments);
        if ($actual < $required) {
            return [$this->error(
                \sprintf(
                    '%s() supplies %s for %s::%s(), but the method requires %s.',
                    $selector,
                    $this->argumentCount($actual),
                    $target->getDisplayName(),
                    $method,
                    $this->argumentCount($required),
                ),
                'arity',
                $call->getStartLine(),
            )];
        }

        if (!$variadic && $actual > \count($parameters)) {
            return [$this->error(
                \sprintf(
                    '%s() supplies %s for %s::%s(), but the method accepts at most %s.',
                    $selector,
                    $this->argumentCount($actual),
                    $target->getDisplayName(),
                    $method,
                    $this->argumentCount(\count($parameters)),
                ),
                'arity',
                $call->getStartLine(),
            )];
        }

        $errors = [];
        $matcher = new ObjectType(ArgumentMatcher::class);

        foreach ($arguments as $index => $argument) {
            $parameter = $parameters[\min($index, \count($parameters) - 1)];
            $argumentType = $scope->getType($argument->value);

            if (!$matcher->isSuperTypeOf($argumentType)->no()
                || !$parameter->getType()->accepts($argumentType, $scope->isDeclareStrictTypes())->no()
            ) {
                continue;
            }

            $errors[] = $this->error(
                \sprintf(
                    '%s() argument #%d for %s::%s() parameter $%s has type %s, but the parameter requires %s.',
                    $selector,
                    $index + 1,
                    $target->getDisplayName(),
                    $method,
                    $parameter->getName(),
                    $argumentType->describe(VerbosityLevel::typeOnly()),
                    $parameter->getType()->describe(VerbosityLevel::typeOnly()),
                ),
                'argument',
                $argument->getStartLine(),
            );
        }

        return $errors;
    }

    /**
     * @param class-string $genericClass
     */
    private function targetClass(Type $receiver, string $genericClass, string $template): ?ClassReflection
    {
        $classes = $receiver->getTemplateType($genericClass, $template)->getObjectClassNames();

        if (\count($classes) !== 1 || !$this->reflectionProvider->hasClass($classes[0])) {
            return null;
        }

        return $this->reflectionProvider->getClass($classes[0]);
    }

    /**
     * @param list<ParametersAcceptor> $acceptors
     */
    private function singleAcceptor(array $acceptors): ?ParametersAcceptor
    {
        return \count($acceptors) === 1 ? $acceptors[0] : null;
    }

    private function isFinal(ExtendedMethodReflection $method): bool
    {
        return $method->isFinal()->yes();
    }

    private function argumentCount(int $count): string
    {
        return \sprintf('%d argument%s', $count, $count === 1 ? '' : 's');
    }

    private function error(string $message, string $identifier, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('greenlight.mockPlan.' . $identifier)
            ->line($line)
            ->build();
    }
}

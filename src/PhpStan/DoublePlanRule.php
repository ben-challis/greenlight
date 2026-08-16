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
use PHPStan\Type\CallableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StaticType;
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
            'times', 'atLeast' => $this->cardinalityErrors($node, $scope),
            'andReturns', 'andReturnsSequence', 'andReturnsUsing' => $this->answerErrors($node, $scope),
            'captureArgument' => $this->captureErrors($node, $scope),
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
        $selected = $this->selectedMethod($scope->getType($call->var));

        if ($selected === null || \array_any($call->getArgs(), static fn(Node\Arg $argument): bool => $argument->unpack)) {
            return [];
        }

        [$target, $method, $acceptor] = $selected;

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
     * @return list<IdentifierRuleError>
     */
    private function cardinalityErrors(MethodCall $call, Scope $scope): array
    {
        if (!$this->isMethodExpectation($scope->getType($call->var))) {
            return [];
        }

        $count = $this->argument($call, 'count', 0);

        if (!$count instanceof Node\Arg) {
            return [];
        }

        $selector = $call->name instanceof Identifier ? $call->name->toString() : '';
        $minimum = $selector === 'times' ? 0 : 1;
        $errors = [];

        foreach ($scope->getType($count->value)->getConstantScalarValues() as $value) {
            if (!\is_int($value) || $value >= $minimum) {
                continue;
            }

            $errors[] = $this->error(
                \sprintf(
                    '%s(%d) requires a count of %s or more.',
                    $selector,
                    $value,
                    $minimum === 0 ? 'zero' : 'one',
                ),
                'cardinality',
                $count->getStartLine(),
            );
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function answerErrors(MethodCall $call, Scope $scope): array
    {
        $selected = $this->selectedMethod($scope->getType($call->var));

        if ($selected === null) {
            return [];
        }

        [$target, $method, $acceptor] = $selected;
        $selector = $call->name instanceof Identifier ? $call->name->toString() : '';
        $arguments = $call->getArgs();
        $returnType = $acceptor->getReturnType();

        if ($selector === 'andReturnsSequence' && $arguments === []) {
            return [$this->error(
                \sprintf('andReturnsSequence() on %s::%s() requires at least one value.', $target->getDisplayName(), $method),
                'answer',
                $call->getStartLine(),
            )];
        }

        if ($selector === 'andReturnsUsing') {
            $answer = $this->argument($call, 'answer', 0);

            if (!$answer instanceof Node\Arg) {
                return [];
            }

            $expected = new CallableType(
                $acceptor->getParameters(),
                $returnType instanceof StaticType ? new MixedType() : $returnType,
                $acceptor->isVariadic(),
            );
            $actual = $scope->getType($answer->value);

            if (!$expected->accepts($actual, $scope->isDeclareStrictTypes())->no()) {
                return [];
            }

            return [$this->error(
                \sprintf(
                    'andReturnsUsing() answer for %s::%s() has type %s, but it requires %s.',
                    $target->getDisplayName(),
                    $method,
                    $actual->describe(VerbosityLevel::typeOnly()),
                    $expected->describe(VerbosityLevel::typeOnly()),
                ),
                'answer',
                $answer->getStartLine(),
            )];
        }

        if ($returnType instanceof StaticType) {
            return [];
        }

        $errors = [];

        foreach ($arguments as $index => $argument) {
            if ($argument->unpack) {
                continue;
            }

            $actual = $scope->getType($argument->value);

            if (!$returnType->accepts($actual, $scope->isDeclareStrictTypes())->no()) {
                continue;
            }

            $errors[] = $this->error(
                \sprintf(
                    '%s() value #%d for %s::%s() has type %s, but the method returns %s.',
                    $selector,
                    $index + 1,
                    $target->getDisplayName(),
                    $method,
                    $actual->describe(VerbosityLevel::typeOnly()),
                    $returnType->describe(VerbosityLevel::typeOnly()),
                ),
                'answer',
                $argument->getStartLine(),
            );
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function captureErrors(MethodCall $call, Scope $scope): array
    {
        $selected = $this->selectedMethod($scope->getType($call->var));

        if ($selected === null) {
            return [];
        }

        [$target, $method, $acceptor] = $selected;
        $positionArgument = $this->argument($call, 'position', 0);
        $positions = $positionArgument instanceof Node\Arg
            ? $scope->getType($positionArgument->value)->getConstantScalarValues()
            : [0];
        $parameters = $acceptor->getParameters();
        $variadic = $parameters !== [] && $parameters[\array_key_last($parameters)]->isVariadic();
        $errors = [];

        foreach ($positions as $position) {
            if (!\is_int($position)) {
                continue;
            }

            if ($position >= 0 && ($variadic || $position < \count($parameters))) {
                continue;
            }

            if ($parameters === []) {
                $errors[] = $this->error(
                    \sprintf(
                        'captureArgument(%d) cannot select an argument on %s::%s() because the method has no parameters.',
                        $position,
                        $target->getDisplayName(),
                        $method,
                    ),
                    'capturePosition',
                    $positionArgument?->getStartLine() ?? $call->getStartLine(),
                );

                continue;
            }

            $requirement = $variadic
                ? 'a position of zero or more'
                : \sprintf('a position from zero to %d', \count($parameters) - 1);
            $errors[] = $this->error(
                \sprintf(
                    'captureArgument(%d) for %s::%s() requires %s.',
                    $position,
                    $target->getDisplayName(),
                    $method,
                    $requirement,
                ),
                'capturePosition',
                $positionArgument?->getStartLine() ?? $call->getStartLine(),
            );
        }

        return $errors;
    }

    private function isMethodExpectation(Type $receiver): bool
    {
        return new ObjectType(MethodExpectation::class)->isSuperTypeOf($receiver)->yes();
    }

    /**
     * @return array{ClassReflection, string, ParametersAcceptor}|null
     */
    private function selectedMethod(Type $receiver): ?array
    {
        if (!$this->isMethodExpectation($receiver)) {
            return null;
        }

        $target = $this->targetClass($receiver, MethodExpectation::class, 'TTarget');
        $methodNames = $receiver->getTemplateType(MethodExpectation::class, 'TMethod')->getConstantStrings();

        if (!$target instanceof ClassReflection || \count($methodNames) !== 1) {
            return null;
        }

        $method = $methodNames[0]->getValue();

        if (!$target->hasNativeMethod($method)) {
            return null;
        }

        $acceptor = $this->singleAcceptor($target->getNativeMethod($method)->getVariants());

        return $acceptor instanceof ParametersAcceptor ? [$target, $method, $acceptor] : null;
    }

    private function argument(MethodCall $call, string $name, int $position): ?Node\Arg
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

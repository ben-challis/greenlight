<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Core\Condition;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ParameterReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\VerbosityLevel;

/**
 * Checks #[SkipUnless] arguments against the referenced condition constructor.
 *
 * @internal
 *
 * @implements Rule<Attribute>
 */
final readonly class SkipUnlessConditionRule implements Rule
{
    public function __construct(private ReflectionProvider $reflectionProvider) {}

    #[\Override]
    public function getNodeType(): string
    {
        return Attribute::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if ($scope->resolveName($node->name) !== SkipUnless::class) {
            return [];
        }

        $condition = $this->conditionArgument($node);

        if (!$condition instanceof Node\Expr) {
            return [];
        }

        $classes = $scope->getType($condition)->getConstantStrings();

        if (\count($classes) !== 1) {
            return [];
        }

        $class = $classes[0]->getValue();

        if (!$this->reflectionProvider->hasClass($class)
            || !$this->reflectionProvider->getClass($class)->implementsInterface(Condition::class)
        ) {
            return [];
        }

        $arguments = $this->conditionArguments($node);

        foreach ($arguments as $argument) {
            $type = $scope->getType($argument);

            if (!$type->isNull()->yes() && !$type->isScalar()->yes()) {
                return [];
            }
        }

        $classReflection = $this->reflectionProvider->getClass($class);
        $parameters = $classReflection->hasConstructor()
            ? $classReflection->getConstructor()->getVariants()[0]->getParameters()
            : [];
        $required = \count(\array_filter(
            $parameters,
            static fn(ParameterReflection $parameter): bool => !$parameter->isOptional() && !$parameter->isVariadic(),
        ));
        $variadic = $parameters !== [] && $parameters[\array_key_last($parameters)]->isVariadic();
        $actual = \count($arguments);

        if ($actual < $required) {
            return [$this->error(
                \sprintf(
                    '%s constructor invoked with %d %s, %d required.',
                    $classReflection->getDisplayName(),
                    $actual,
                    $actual === 1 ? 'parameter' : 'parameters',
                    $required,
                ),
                'arity',
                $node->getStartLine(),
            )];
        }

        if (!$variadic && $actual > \count($parameters)) {
            return [$this->error(
                \sprintf(
                    '%s constructor invoked with %d parameters, %d accepted.',
                    $classReflection->getDisplayName(),
                    $actual,
                    \count($parameters),
                ),
                'arity',
                $node->getStartLine(),
            )];
        }

        $errors = [];

        foreach ($arguments as $index => $argument) {
            $parameter = $parameters[\min($index, \count($parameters) - 1)];
            $argumentType = $scope->getType($argument);

            if ($parameter->getType()->accepts($argumentType, $scope->isDeclareStrictTypes())->yes()) {
                continue;
            }

            $errors[] = $this->error(
                \sprintf(
                    'Parameter #%d $%s of class %s constructor expects %s, %s given.',
                    $index + 1,
                    $parameter->getName(),
                    $classReflection->getDisplayName(),
                    $parameter->getType()->describe(VerbosityLevel::typeOnly()),
                    $argumentType->describe(VerbosityLevel::typeOnly()),
                ),
                'argument',
                $argument->getStartLine(),
            );
        }

        return $errors;
    }

    private function conditionArgument(Attribute $attribute): ?Node\Expr
    {
        foreach ($attribute->args as $index => $argument) {
            if ($argument->name === null ? $index === 0 : $argument->name->toString() === 'condition') {
                return $argument->value;
            }
        }

        return null;
    }

    /**
     * Greenlight removes variadic argument names before it calls the condition
     * constructor. Thus, the rule checks arguments in declaration order.
     *
     * @return list<Node\Expr>
     */
    private function conditionArguments(Attribute $attribute): array
    {
        $arguments = [];

        foreach ($attribute->args as $index => $argument) {
            if ($argument->name === null ? $index === 0 : $argument->name->toString() === 'condition') {
                continue;
            }

            $arguments[] = $argument->value;
        }

        return $arguments;
    }

    private function error(string $message, string $identifier, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('greenlight.skipUnlessCondition.' . $identifier)
            ->line($line)
            ->build();
    }
}

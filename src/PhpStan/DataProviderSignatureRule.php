<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\DataSet;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

/**
 * Checks #[DataSet] providers and #[DataRow] rows against the test method
 * they feed, so a bad data set fails analysis instead of the run.
 *
 * A provider must exist on the same class as a public static method
 * returning an iterable of argument arrays. Where PHPStan knows a row's
 * exact shape (an array{...} return type or an inline #[DataRow] literal),
 * each value is checked against the matching parameter, and rows with too
 * few or too many values are flagged.
 *
 * Rows without a known shape are only required to be arrays; what is in
 * them stays a runtime concern.
 *
 * @implements Rule<InClassMethodNode>
 */
final class DataProviderSignatureRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->getMethodReflection();
        $acceptor = null;
        $errors = [];

        foreach ($node->getOriginalNode()->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = $scope->resolveName($attribute->name);

                if ($name !== DataRow::class && $name !== DataSet::class) {
                    continue;
                }

                $acceptor ??= $this->singleAcceptor($method->getVariants());

                if (!$acceptor instanceof ParametersAcceptor) {
                    return [];
                }

                $errors = [...$errors, ...($name === DataRow::class
                    ? $this->checkDataRow($attribute, $acceptor, $method->getName(), $scope)
                    : $this->checkDataSet($attribute, $acceptor, $node->getClassReflection(), $method->getName(), $scope))];
            }
        }

        return $errors;
    }

    /**
     * A method with anything other than exactly one variant has no single
     * signature to validate rows against, so it is skipped.
     *
     * @param list<ParametersAcceptor> $variants
     */
    private function singleAcceptor(array $variants): ?ParametersAcceptor
    {
        return \count($variants) === 1 ? $variants[0] : null;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkDataRow(Attribute $attribute, ParametersAcceptor $acceptor, string $methodName, Scope $scope): array
    {
        $argumentsExpression = $this->attributeArgument($attribute, 0, 'arguments');

        if (!$argumentsExpression instanceof Node\Expr) {
            return [];
        }

        $errors = [];

        foreach ($scope->getType($argumentsExpression)->getConstantArrays() as $row) {
            $errors = [...$errors, ...$this->checkRow(
                \array_values($row->getValueTypes()),
                $acceptor,
                $methodName,
                '#[DataRow]',
                $attribute->getStartLine(),
            )];
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkDataSet(Attribute $attribute, ParametersAcceptor $acceptor, ClassReflection $class, string $methodName, Scope $scope): array
    {
        $providerExpression = $this->attributeArgument($attribute, 0, 'provider');

        if (!$providerExpression instanceof Node\Expr) {
            return [];
        }

        $providerNames = $scope->getType($providerExpression)->getConstantStrings();

        if (\count($providerNames) !== 1) {
            return [];
        }

        $provider = $providerNames[0]->getValue();
        $line = $attribute->getStartLine();

        if (!$class->hasMethod($provider)) {
            return [$this->error(
                \sprintf('Data provider %s() for %s() does not exist on %s.', $provider, $methodName, $class->getDisplayName()),
                'provider',
                $line,
            )];
        }

        $providerMethod = $class->getMethod($provider, $scope);

        if (!$providerMethod->isStatic() || !$providerMethod->isPublic()) {
            return [$this->error(
                \sprintf('Data provider %s::%s() must be public and static.', $class->getDisplayName(), $provider),
                'provider',
                $line,
            )];
        }

        $providerAcceptor = $this->singleAcceptor($providerMethod->getVariants());

        if (!$providerAcceptor instanceof ParametersAcceptor) {
            return [];
        }

        $returnType = $providerAcceptor->getReturnType();

        if ($returnType->isIterable()->no()) {
            return [$this->error(
                \sprintf(
                    'Data provider %s::%s() must return an iterable of argument arrays, returns %s.',
                    $class->getDisplayName(),
                    $provider,
                    $returnType->describe(VerbosityLevel::typeOnly()),
                ),
                'returnType',
                $line,
            )];
        }

        $rowType = $returnType->getIterableValueType();

        if ($rowType->isArray()->no()) {
            return [$this->error(
                \sprintf(
                    'Data provider %s::%s() must yield arrays of arguments, yields %s.',
                    $class->getDisplayName(),
                    $provider,
                    $rowType->describe(VerbosityLevel::typeOnly()),
                ),
                'returnType',
                $line,
            )];
        }

        $errors = [];

        foreach ($rowType->getConstantArrays() as $row) {
            $errors = [...$errors, ...$this->checkRow(
                \array_values($row->getValueTypes()),
                $acceptor,
                $methodName,
                \sprintf('Data provider %s() row', $provider),
                $line,
            )];
        }

        return $errors;
    }

    /**
     * Positional check of one row against the method's parameters. Rows are
     * applied with array_values() at run time, so only value order matters.
     *
     * @param list<Type> $valueTypes
     *
     * @return list<IdentifierRuleError>
     */
    private function checkRow(array $valueTypes, ParametersAcceptor $acceptor, string $methodName, string $source, int $line): array
    {
        $parameters = $acceptor->getParameters();
        $required = \count(\array_filter($parameters, static fn($parameter): bool => !$parameter->isOptional()));
        $count = \count($valueTypes);

        if ($count < $required || (!$acceptor->isVariadic() && $count > \count($parameters))) {
            return [$this->error(
                \sprintf(
                    '%s supplies %d argument%s, but %s() expects %s.',
                    $source,
                    $count,
                    $count === 1 ? '' : 's',
                    $methodName,
                    $this->expectedArity($required, \count($parameters), $acceptor->isVariadic()),
                ),
                'arity',
                $line,
            )];
        }

        $errors = [];

        foreach ($valueTypes as $position => $valueType) {
            $parameter = $parameters[\min($position, \count($parameters) - 1)];

            if ($parameter->getType()->accepts($valueType, true)->no()) {
                $errors[] = $this->error(
                    \sprintf(
                        '%s argument #%d of %s() expects %s, %s given.',
                        $source,
                        $position + 1,
                        $methodName,
                        $parameter->getType()->describe(VerbosityLevel::typeOnly()),
                        $valueType->describe(VerbosityLevel::typeOnly()),
                    ),
                    'argument',
                    $line,
                );
            }
        }

        return $errors;
    }

    private function expectedArity(int $required, int $total, bool $variadic): string
    {
        if ($variadic) {
            return \sprintf('at least %d', $required);
        }

        if ($required === $total) {
            return \sprintf('exactly %d', $total);
        }

        return \sprintf('between %d and %d', $required, $total);
    }

    private function attributeArgument(Attribute $attribute, int $position, string $name): ?Node\Expr
    {
        foreach ($attribute->args as $index => $argument) {
            if ($argument->name === null ? $index === $position : $argument->name->toString() === $name) {
                return $argument->value;
            }
        }

        return null;
    }

    private function error(string $message, string $identifier, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('greenlight.dataProvider.' . $identifier)
            ->line($line)
            ->build();
    }
}

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
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\IntegerType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * A data provider must be a public, static, concrete method that does not
 * require arguments. It must be in the test class or the specified provider
 * class. It must return an iterable of argument arrays.
 *
 * PHPStan can know the exact form of an `array{...}` return type or an inline
 * `#[DataRow]` literal. In these forms, PHPStan compares each value to its
 * parameter. It also reports too few or too many values. For other forms,
 * PHPStan verifies only that each data set is an array. Greenlight validates
 * its content at run time.
 *
 * @internal
 *
 * @implements Rule<InClassMethodNode>
 */
final readonly class DataProviderSignatureRule implements Rule
{
    public function __construct(private ReflectionProvider $reflectionProvider) {}

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
        $errors = $this->checkDataRowKeys($node, $scope);

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
     * @return list<IdentifierRuleError>
     */
    private function checkDataRowKeys(InClassMethodNode $node, Scope $scope): array
    {
        $keys = [];
        $position = 0;
        $errors = [];

        foreach ($node->getOriginalNode()->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($scope->resolveName($attribute->name) !== DataRow::class) {
                    continue;
                }

                $label = $this->attributeArgument($attribute, 1, 'label');
                $labels = $label instanceof Node\Expr
                    ? $scope->getType($label)->getConstantStrings()
                    : [];
                $key = \count($labels) === 1
                    ? $labels[0]->getValue()
                    : ($label instanceof Node\Expr ? null : \sprintf('#%d', $position));

                if ($key !== null && isset($keys[$key])) {
                    $errors[] = $this->error(
                        \sprintf('#[DataRow] key "%s" occurs more than once on %s().', $key, $node->getMethodReflection()->getName()),
                        'duplicateKey',
                        $attribute->getStartLine(),
                    );
                }

                if ($key !== null) {
                    $keys[$key] = true;
                }

                ++$position;
            }
        }

        return $errors;
    }

    /**
     * If a method does not have exactly one variant, it has no single
     * signature. Thus, the rule does not validate its data sets.
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
        $methodExpression = $this->attributeArgument($attribute, 1, 'method');

        if (!$providerExpression instanceof Node\Expr) {
            return [];
        }

        $providerClass = $class;

        if ($methodExpression instanceof Node\Expr) {
            $providerClasses = $scope->getType($providerExpression)->getConstantStrings();

            if (\count($providerClasses) !== 1) {
                return [];
            }

            $providerClassName = $providerClasses[0]->getValue();

            if (!$this->reflectionProvider->hasClass($providerClassName)) {
                return [$this->error(
                    \sprintf(
                        'Data provider class %s referenced by %s() does not exist.',
                        $providerClassName,
                        $methodName,
                    ),
                    'provider',
                    $attribute->getStartLine(),
                )];
            }

            $providerClass = $this->reflectionProvider->getClass($providerClassName);
            $providerExpression = $methodExpression;
        }

        $providerNames = $scope->getType($providerExpression)->getConstantStrings();

        if (\count($providerNames) !== 1) {
            return [];
        }

        $provider = $providerNames[0]->getValue();
        $line = $attribute->getStartLine();

        if (!$providerClass->hasNativeMethod($provider)) {
            return [$this->error(
                \sprintf(
                    'Data provider %s() for %s() does not exist on %s.',
                    $provider,
                    $methodName,
                    $providerClass->getDisplayName(),
                ),
                'provider',
                $line,
            )];
        }

        $providerMethod = $providerClass->getNativeMethod($provider);

        if (!$providerMethod->isStatic() || !$providerMethod->isPublic()) {
            return [$this->error(
                \sprintf('Data provider %s::%s() must be public and static.', $providerClass->getDisplayName(), $provider),
                'provider',
                $line,
            )];
        }

        if ($this->isAbstract($providerMethod)) {
            return [$this->error(
                \sprintf('Data provider %s::%s() must be concrete.', $providerClass->getDisplayName(), $provider),
                'provider',
                $line,
            )];
        }

        $providerAcceptor = $this->singleAcceptor($providerMethod->getVariants());

        if (!$providerAcceptor instanceof ParametersAcceptor) {
            return [];
        }

        if ($this->requiredParameterCount($providerAcceptor) > 0) {
            return [$this->error(
                \sprintf('Data provider %s::%s() must not require arguments.', $providerClass->getDisplayName(), $provider),
                'parameters',
                $line,
            )];
        }

        $returnType = $providerAcceptor->getReturnType();

        if ($returnType->isIterable()->no()) {
            return [$this->error(
                \sprintf(
                    'Data provider %s::%s() must return an iterable of argument arrays, returns %s.',
                    $providerClass->getDisplayName(),
                    $provider,
                    $returnType->describe(VerbosityLevel::typeOnly()),
                ),
                'returnType',
                $line,
            )];
        }

        if ($returnType->isIterableAtLeastOnce()->no()) {
            return [$this->error(
                \sprintf('Data provider %s::%s() must yield at least one argument array.', $providerClass->getDisplayName(), $provider),
                'empty',
                $line,
            )];
        }

        $rowType = $returnType->getIterableValueType();

        if ($rowType->isArray()->no()) {
            return [$this->error(
                \sprintf(
                    'Data provider %s::%s() must yield arrays of arguments, yields %s.',
                    $providerClass->getDisplayName(),
                    $provider,
                    $rowType->describe(VerbosityLevel::typeOnly()),
                ),
                'returnType',
                $line,
            )];
        }

        $keyType = $returnType->getIterableKeyType();
        $allowedKeyType = TypeCombinator::union(new IntegerType(), new StringType());

        if ($allowedKeyType->isSuperTypeOf($keyType)->no()) {
            return [$this->error(
                \sprintf(
                    'Data provider %s::%s() keys must be int or string. The provider returns keys of type %s.',
                    $providerClass->getDisplayName(),
                    $provider,
                    $keyType->describe(VerbosityLevel::typeOnly()),
                ),
                'keyType',
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

    private function isAbstract(ExtendedMethodReflection $method): bool
    {
        $abstract = $method->isAbstract();

        return \is_bool($abstract) ? $abstract : $abstract->yes();
    }

    private function requiredParameterCount(ParametersAcceptor $acceptor): int
    {
        return \count(\array_filter(
            $acceptor->getParameters(),
            static fn($parameter): bool => !$parameter->isOptional(),
        ));
    }

    /**
     * Compares one data set to the method parameters by position.
     *
     * Greenlight applies array_values() at run time. Thus, only value order is
     * applicable.
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

<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Group;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Skip;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Timeout;
use Greenlight\Core\Condition;
use Greenlight\Core\Test\ResourceName;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Validates constant attribute values whose domains PHP types cannot express.
 *
 * @internal
 *
 * @implements Rule<Attribute>
 */
final readonly class AttributeArgumentRule implements Rule
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
        $name = $scope->resolveName($node->name);

        if ($name === Timeout::class) {
            return $this->checkTimeout($node, $scope);
        }

        if ($name === Retry::class) {
            return $this->checkRetry($node, $scope);
        }

        if ($name === SkipUnless::class) {
            return $this->checkSkipUnless($node, $scope);
        }

        if ($name === RequiresResource::class) {
            return $this->checkResource($node, $scope);
        }

        if ($name === Group::class) {
            return $this->checkNonEmptyString($node, $scope, 0, 'name', 'Group', 'group');
        }

        if ($name === Skip::class) {
            return $this->checkNonEmptyString($node, $scope, 0, 'reason', 'Skip', 'skip');
        }

        if ($name === DataRow::class) {
            return $this->checkNonEmptyString($node, $scope, 1, 'label', 'DataRow', 'dataRow');
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkTimeout(Attribute $attribute, Scope $scope): array
    {
        $argument = $this->argument($attribute, 0, 'seconds');

        if (!$argument instanceof Node\Expr) {
            return [];
        }

        $values = $scope->getType($argument)->getConstantScalarValues();

        if (\count($values) !== 1 || !\is_int($values[0]) && !\is_float($values[0])) {
            return [];
        }

        $seconds = (float) $values[0];

        if (\is_finite($seconds) && $seconds > 0.0) {
            return [];
        }

        return [$this->error(
            '#[Timeout] seconds must be finite and greater than zero.',
            'timeout',
            $attribute->getStartLine(),
        )];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkRetry(Attribute $attribute, Scope $scope): array
    {
        $errors = [];
        $times = $this->argument($attribute, 0, 'times');

        if ($times instanceof Node\Expr) {
            $values = $scope->getType($times)->getConstantScalarValues();

            if (\count($values) === 1 && \is_int($values[0]) && $values[0] < 1) {
                $errors[] = $this->error(
                    '#[Retry] times must be at least 1.',
                    'retry',
                    $attribute->getStartLine(),
                );
            }
        }

        $onlyOn = $this->argument($attribute, 1, 'onlyOn');

        if ($onlyOn instanceof Node\Expr
            && !$scope->getType($onlyOn)->isNull()->yes()
            && !$this->isInstantiableClass($onlyOn, $scope, \Throwable::class)
        ) {
            $errors[] = $this->error(
                '#[Retry] onlyOn must name an instantiable Throwable class.',
                'retry',
                $onlyOn->getStartLine(),
            );
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkSkipUnless(Attribute $attribute, Scope $scope): array
    {
        $errors = [];
        $condition = $this->argument($attribute, 0, 'condition');

        if ($condition instanceof Node\Expr
            && !$this->isInstantiableClass($condition, $scope, Condition::class)
        ) {
            $errors[] = $this->error(
                '#[SkipUnless] condition must name an instantiable Condition class.',
                'skipUnless',
                $condition->getStartLine(),
            );
        }

        $conditionArgumentNumber = 0;

        foreach ($attribute->args as $index => $argument) {
            if ($argument->name === null ? $index === 0 : $argument->name->toString() === 'condition') {
                continue;
            }

            ++$conditionArgumentNumber;
            $type = $scope->getType($argument->value);

            if ($type->isNull()->yes()) {
                continue;
            }

            if ($type->isScalar()->yes()) {
                $values = $type->getConstantScalarValues();

                if (!\array_any($values, static fn(mixed $value): bool => \is_float($value) && !\is_finite($value))) {
                    continue;
                }

                $errors[] = $this->error(
                    \sprintf('#[SkipUnless] argument %d must be a finite float.', $conditionArgumentNumber),
                    'skipUnless',
                    $argument->getStartLine(),
                );

                continue;
            }

            $errors[] = $this->error(
                \sprintf('#[SkipUnless] argument %d must be a scalar or null.', $conditionArgumentNumber),
                'skipUnless',
                $argument->getStartLine(),
            );
        }

        return $errors;
    }

    /**
     * @param class-string $parent
     */
    private function isInstantiableClass(Node\Expr $argument, Scope $scope, string $parent): bool
    {
        $classes = $scope->getType($argument)->getConstantStrings();

        if (\count($classes) !== 1) {
            return true;
        }

        $class = $classes[0]->getValue();

        if (!$this->reflectionProvider->hasClass($class)) {
            return false;
        }

        return new ObjectType($parent)->isSuperTypeOf(new ObjectType($class))->yes()
            && $this->reflectionProvider->getClass($class)->getNativeReflection()->isInstantiable();
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkNonEmptyString(
        Attribute $attribute,
        Scope $scope,
        int $position,
        string $name,
        string $displayName,
        string $identifier,
    ): array {
        $argument = $this->argument($attribute, $position, $name);

        if (!$argument instanceof Node\Expr) {
            return [];
        }

        $strings = $scope->getType($argument)->getConstantStrings();

        if (\count($strings) !== 1 || $strings[0]->getValue() !== '') {
            return [];
        }

        return [$this->error(
            \sprintf('#[%s] %s must not be empty.', $displayName, $name),
            $identifier,
            $argument->getStartLine(),
        )];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkResource(Attribute $attribute, Scope $scope): array
    {
        $argument = $this->argument($attribute, 0, 'name');

        if (!$argument instanceof Node\Expr) {
            return [];
        }

        $names = $scope->getType($argument)->getConstantStrings();

        if (\count($names) !== 1) {
            return [];
        }

        $name = $names[0]->getValue();

        if (ResourceName::isValid($name)) {
            return [];
        }

        return [$this->error(
            \sprintf('#[RequiresResource] name "%s" does not match %s.', $name, ResourceName::PATTERN),
            'resource',
            $attribute->getStartLine(),
        )];
    }

    private function argument(Attribute $attribute, int $position, string $name): ?Node\Expr
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
            ->identifier('greenlight.attributeArgument.' . $identifier)
            ->line($line)
            ->build();
    }
}

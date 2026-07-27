<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Retry;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Timeout;
use Greenlight\Core\Test\ResourceName;
use PhpParser\Node;
use PhpParser\Node\Attribute;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Validates constant attribute values whose domains PHP types cannot express.
 *
 * @internal
 *
 * @implements Rule<Attribute>
 */
final class AttributeArgumentRule implements Rule
{
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
        $argument = $this->argument($attribute, 0, 'times');

        if (!$argument instanceof Node\Expr) {
            return [];
        }

        $values = $scope->getType($argument)->getConstantScalarValues();

        if (\count($values) !== 1 || !\is_int($values[0]) || $values[0] > 0) {
            return [];
        }

        return [$this->error(
            '#[Retry] times must be at least 1.',
            'retry',
            $attribute->getStartLine(),
        )];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function checkSkipUnless(Attribute $attribute, Scope $scope): array
    {
        $errors = [];
        $conditionArgumentNumber = 0;

        foreach ($attribute->args as $index => $argument) {
            if ($argument->name === null ? $index === 0 : $argument->name->toString() === 'condition') {
                continue;
            }

            ++$conditionArgumentNumber;
            $type = $scope->getType($argument->value);

            if ($type->isNull()->yes() || $type->isScalar()->yes()) {
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

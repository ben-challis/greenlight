<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Config\CoverageBuilder;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Validates constant coverage-builder values whose domains PHP types cannot express.
 *
 * @internal
 *
 * @implements Rule<MethodCall>
 */
final class CoverageBuilderArgumentRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->name->toString() !== 'minimumPercentage') {
            return [];
        }

        if (!new ObjectType(CoverageBuilder::class)->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        $argument = $this->argument($node);

        if (!$argument instanceof Node\Expr) {
            return [];
        }

        $values = $scope->getType($argument)->getConstantScalarValues();

        if (\count($values) !== 1 || !\is_int($values[0]) && !\is_float($values[0])) {
            return [];
        }

        $percentage = (float) $values[0];

        if (!\is_finite($percentage) || $percentage < 0.0 || $percentage > 100.0) {
            return [$this->error(
                'Minimum coverage percentage must be from 0 through 100.',
                'range',
                $argument->getStartLine(),
            )];
        }

        if (\round($percentage, 2) !== $percentage) {
            return [$this->error(
                'Minimum coverage percentage can have at most two decimal places.',
                'precision',
                $argument->getStartLine(),
            )];
        }

        return [];
    }

    private function argument(MethodCall $call): ?Node\Expr
    {
        foreach ($call->args as $index => $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }

            if (!$argument->name instanceof Identifier ? $index === 0 : $argument->name->toString() === 'percentage') {
                return $argument->value;
            }
        }

        return null;
    }

    private function error(string $message, string $identifier, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('greenlight.coverageBuilderArgument.' . $identifier)
            ->line($line)
            ->build();
    }
}

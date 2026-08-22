<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Expect\ConsistentlyExpectation;
use Greenlight\Expect\EventuallyExpectation;
use Greenlight\Expect\Expectation;
use Greenlight\Expect\PendingConsistently;
use Greenlight\Expect\PendingEventually;
use Greenlight\Expect\TemporalExpectation;
use Greenlight\Internal\Php\ErrorTrap;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * Checks constant expectation arguments that have value constraints.
 *
 * @internal
 *
 * @implements Rule<MethodCall>
 */
final class ExpectationArgumentRule implements Rule
{
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

        $method = $node->name->toString();

        if ($this->isExpectation($scope->getType($node->var))) {
            return match ($method) {
                'toMatch' => $this->patternErrors($node, $scope, $method, 'pattern', 0),
                'toThrow' => $this->patternErrors($node, $scope, $method, 'matching', 1),
                'toMatchJson' => $this->jsonErrors($node, $scope),
                'toBeWithin' => $this->toleranceErrors($node, $scope),
                'because' => $this->reasonErrors($node, $scope),
                default => [],
            };
        }

        if ($method === 'within' && $this->isType($scope, $node, PendingEventually::class)) {
            return $this->durationErrors($node, $scope, $method, 0.0, false);
        }

        if ($method === 'for' && $this->isType($scope, $node, PendingConsistently::class)) {
            return $this->durationErrors($node, $scope, $method, 0.0, false);
        }

        if ($method === 'pollEvery'
            && ($this->isType($scope, $node, PendingEventually::class)
                || $this->isType($scope, $node, PendingConsistently::class))
        ) {
            return $this->durationErrors($node, $scope, $method, 0.001, true);
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function toleranceErrors(MethodCall $call, Scope $scope): array
    {
        $argument = $this->argument($call, 'delta', 0);

        if (!$argument instanceof Arg) {
            return [];
        }

        foreach ($scope->getType($argument->value)->getConstantScalarValues() as $delta) {
            if ((\is_int($delta) || \is_float($delta))
                && \is_finite((float) $delta)
                && $delta >= 0.0
            ) {
                continue;
            }

            return [RuleErrorBuilder::message('toBeWithin() requires a finite tolerance of zero or more.')
                ->identifier('greenlight.expectationArgument.tolerance')
                ->line($call->getStartLine())
                ->build()];
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function reasonErrors(MethodCall $call, Scope $scope): array
    {
        $argument = $this->argument($call, 'reason', 0);

        if (!$argument instanceof Arg) {
            return [];
        }

        foreach ($scope->getType($argument->value)->getConstantStrings() as $reason) {
            $value = $reason->getValue();

            if ($value === '' || \trim($value) !== '') {
                continue;
            }

            return [RuleErrorBuilder::message('because() requires a non-empty reason.')
                ->identifier('greenlight.expectationArgument.reason')
                ->line($call->getStartLine())
                ->build()];
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function patternErrors(
        MethodCall $call,
        Scope $scope,
        string $method,
        string $name,
        int $position,
    ): array {
        $argument = $this->argument($call, $name, $position);

        if (!$argument instanceof Arg) {
            return [];
        }

        foreach ($scope->getType($argument->value)->getConstantStrings() as $pattern) {
            $matched = ErrorTrap::run(static fn() => \preg_match($pattern->getValue(), ''), $warning);

            if ($matched !== false) {
                continue;
            }

            return [RuleErrorBuilder::message(\sprintf(
                'Regular expression "%s" for %s() is invalid.',
                $pattern->getValue(),
                $method,
            ))
                ->identifier('greenlight.expectationArgument.pattern')
                ->line($call->getStartLine())
                ->build()];
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function jsonErrors(MethodCall $call, Scope $scope): array
    {
        $argument = $this->argument($call, 'expected', 0);

        if (!$argument instanceof Arg) {
            return [];
        }

        foreach ($scope->getType($argument->value)->getConstantStrings() as $expected) {
            if (\json_validate($expected->getValue())) {
                continue;
            }

            return [RuleErrorBuilder::message('toMatchJson() requires valid expected JSON.')
                ->identifier('greenlight.expectationArgument.json')
                ->line($call->getStartLine())
                ->build()];
        }

        return [];
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function durationErrors(
        MethodCall $call,
        Scope $scope,
        string $method,
        float $minimum,
        bool $inclusive,
    ): array {
        $argument = $this->argument($call, 'seconds', 0);

        if (!$argument instanceof Arg) {
            return [];
        }

        foreach ($scope->getType($argument->value)->getConstantScalarValues() as $seconds) {
            if ((\is_int($seconds) || \is_float($seconds))
                && \is_finite((float) $seconds)
                && ($inclusive ? $seconds >= $minimum : $seconds > $minimum)
            ) {
                continue;
            }

            $constraint = $inclusive
                ? \sprintf('at least %.3f seconds', $minimum)
                : \sprintf('greater than %.3f seconds', $minimum);

            return [RuleErrorBuilder::message(\sprintf(
                '%s() requires a finite duration %s.',
                $method,
                $constraint,
            ))
                ->identifier('greenlight.expectationArgument.duration')
                ->line($call->getStartLine())
                ->build()];
        }

        return [];
    }

    private function argument(MethodCall $call, string $name, int $position): ?Arg
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

    private function isExpectation(Type $receiver): bool
    {
        return \array_any(
            [Expectation::class, TemporalExpectation::class, EventuallyExpectation::class, ConsistentlyExpectation::class],
            static fn(string $class): bool => new ObjectType($class)->isSuperTypeOf($receiver)->yes(),
        );
    }

    /**
     * @param class-string $class
     */
    private function isType(Scope $scope, MethodCall $call, string $class): bool
    {
        return new ObjectType($class)->isSuperTypeOf($scope->getType($call->var))->yes();
    }
}

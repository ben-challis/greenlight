<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Config\CoverageBuilder;
use Greenlight\Expect\ConsistentlyExpectation;
use Greenlight\Expect\EventuallyExpectation;
use Greenlight\Expect\Expectation;
use Greenlight\Expect\PendingConsistently;
use Greenlight\Expect\PendingEventually;
use Greenlight\Expect\TemporalExpectation;
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
 * Checks constant float arguments against method-specific value constraints.
 *
 * @internal
 *
 * @phpstan-type Constraint array{
 *     receivers: non-empty-list<class-string>,
 *     method: non-empty-string,
 *     argument: non-empty-string,
 *     position: int<0, max>,
 *     minimum: float|null,
 *     minimumInclusive: bool,
 *     maximum: float|null,
 *     maximumInclusive: bool,
 *     rejectNonNumeric: bool,
 *     precision: int<0, max>|null,
 *     rangeMessage: non-empty-string,
 *     rangeIdentifier: non-empty-string,
 *     precisionMessage: non-empty-string|null,
 *     precisionIdentifier: non-empty-string|null
 * }
 *
 * @implements Rule<MethodCall>
 */
final class FloatArgumentRule implements Rule
{
    /** @var list<Constraint> */
    private const array CONSTRAINTS = [
        [
            'receivers' => [CoverageBuilder::class],
            'method' => 'minimumPercentage',
            'argument' => 'percentage',
            'position' => 0,
            'minimum' => 0.0,
            'minimumInclusive' => true,
            'maximum' => 100.0,
            'maximumInclusive' => true,
            'rejectNonNumeric' => false,
            'precision' => 2,
            'rangeMessage' => 'Minimum coverage percentage must be from 0 through 100.',
            'rangeIdentifier' => 'greenlight.coverageBuilderArgument.range',
            'precisionMessage' => 'Minimum coverage percentage can have at most two decimal places.',
            'precisionIdentifier' => 'greenlight.coverageBuilderArgument.precision',
        ],
        [
            'receivers' => [Expectation::class, TemporalExpectation::class, EventuallyExpectation::class, ConsistentlyExpectation::class],
            'method' => 'toBeWithin',
            'argument' => 'delta',
            'position' => 0,
            'minimum' => 0.0,
            'minimumInclusive' => true,
            'maximum' => null,
            'maximumInclusive' => false,
            'rejectNonNumeric' => true,
            'precision' => null,
            'rangeMessage' => 'toBeWithin() requires a finite tolerance of zero or more.',
            'rangeIdentifier' => 'greenlight.expectationArgument.tolerance',
            'precisionMessage' => null,
            'precisionIdentifier' => null,
        ],
        [
            'receivers' => [PendingEventually::class, PendingConsistently::class],
            'method' => 'pollEvery',
            'argument' => 'seconds',
            'position' => 0,
            'minimum' => 0.001,
            'minimumInclusive' => true,
            'maximum' => null,
            'maximumInclusive' => false,
            'rejectNonNumeric' => true,
            'precision' => null,
            'rangeMessage' => 'pollEvery() requires a finite duration at least 0.001 seconds.',
            'rangeIdentifier' => 'greenlight.expectationArgument.duration',
            'precisionMessage' => null,
            'precisionIdentifier' => null,
        ],
        [
            'receivers' => [PendingEventually::class],
            'method' => 'within',
            'argument' => 'seconds',
            'position' => 0,
            'minimum' => 0.0,
            'minimumInclusive' => false,
            'maximum' => null,
            'maximumInclusive' => false,
            'rejectNonNumeric' => true,
            'precision' => null,
            'rangeMessage' => 'within() requires a finite duration greater than 0.000 seconds.',
            'rangeIdentifier' => 'greenlight.expectationArgument.duration',
            'precisionMessage' => null,
            'precisionIdentifier' => null,
        ],
        [
            'receivers' => [PendingConsistently::class],
            'method' => 'for',
            'argument' => 'seconds',
            'position' => 0,
            'minimum' => 0.0,
            'minimumInclusive' => false,
            'maximum' => null,
            'maximumInclusive' => false,
            'rejectNonNumeric' => true,
            'precision' => null,
            'rangeMessage' => 'for() requires a finite duration greater than 0.000 seconds.',
            'rangeIdentifier' => 'greenlight.expectationArgument.duration',
            'precisionMessage' => null,
            'precisionIdentifier' => null,
        ],
    ];

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

        $receiver = $scope->getType($node->var);

        foreach (self::CONSTRAINTS as $constraint) {
            if ($node->name->toString() !== $constraint['method'] || !$this->supports($receiver, $constraint)) {
                continue;
            }

            $argument = $this->argument($node, $constraint['argument'], $constraint['position']);

            if (!$argument instanceof Arg) {
                return [];
            }

            foreach ($scope->getType($argument->value)->getConstantScalarValues() as $value) {
                if (!\is_int($value) && !\is_float($value)) {
                    if ($constraint['rejectNonNumeric']) {
                        return [$this->error(
                            $constraint['rangeMessage'],
                            $constraint['rangeIdentifier'],
                            $node->getStartLine(),
                        )];
                    }

                    continue;
                }

                $error = $this->validate((float) $value, $constraint, $argument->getStartLine());

                if ($error instanceof IdentifierRuleError) {
                    return [$error];
                }
            }

            return [];
        }

        return [];
    }

    /**
     * @param Constraint $constraint
     */
    private function supports(Type $receiver, array $constraint): bool
    {
        return \array_any(
            $constraint['receivers'],
            static fn(string $class): bool => new ObjectType($class)->isSuperTypeOf($receiver)->yes(),
        );
    }

    /**
     * @param Constraint $constraint
     */
    private function validate(float $value, array $constraint, int $line): ?IdentifierRuleError
    {
        if (!\is_finite($value)
            || !$this->aboveMinimum($value, $constraint['minimum'], $constraint['minimumInclusive'])
            || !$this->belowMaximum($value, $constraint['maximum'], $constraint['maximumInclusive'])
        ) {
            return $this->error($constraint['rangeMessage'], $constraint['rangeIdentifier'], $line);
        }

        if ($constraint['precision'] === null || \round($value, $constraint['precision']) === $value) {
            return null;
        }

        if ($constraint['precisionMessage'] === null || $constraint['precisionIdentifier'] === null) {
            throw new \LogicException('A float precision constraint requires an error message and identifier.');
        }

        return $this->error($constraint['precisionMessage'], $constraint['precisionIdentifier'], $line);
    }

    private function aboveMinimum(float $value, ?float $minimum, bool $inclusive): bool
    {
        return $minimum === null || ($inclusive ? $value >= $minimum : $value > $minimum);
    }

    private function belowMaximum(float $value, ?float $maximum, bool $inclusive): bool
    {
        return $maximum === null || ($inclusive ? $value <= $maximum : $value < $maximum);
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

    private function error(string $message, string $identifier, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier($identifier)
            ->line($line)
            ->build();
    }
}

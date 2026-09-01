<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Attribute\Timeout;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Validates constant values that construct a Timeout attribute.
 *
 * @internal
 *
 * @implements Rule<New_>
 */
final readonly class TimeoutConstructorRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return New_::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->class instanceof Name || $scope->resolveName($node->class) !== Timeout::class) {
            return [];
        }

        $argument = $this->argument($node);

        if (!$argument instanceof Arg) {
            return [];
        }

        $values = $scope->getType($argument->value)->getConstantScalarValues();

        if (\count($values) !== 1 || !\is_int($values[0]) && !\is_float($values[0])) {
            return [];
        }

        $seconds = (float) $values[0];

        if (\is_finite($seconds) && $seconds > 0.0) {
            return [];
        }

        return [RuleErrorBuilder::message('Timeout seconds must be finite and greater than zero.')
            ->identifier('greenlight.timeoutConstructor.seconds')
            ->line($argument->getStartLine())
            ->build()];
    }

    private function argument(New_ $new): ?Arg
    {
        foreach ($new->getArgs() as $index => $argument) {
            if ($argument->name instanceof Identifier
                ? $argument->name->toString() === 'seconds'
                : $index === 0
            ) {
                return $argument;
            }
        }

        return null;
    }
}

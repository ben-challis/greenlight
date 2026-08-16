<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Doubles\Doubles;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\VerbosityLevel;

/**
 * Checks constant `callsTo()` method names against the doubled type.
 *
 * @internal
 *
 * @implements Rule<MethodCall>
 */
final readonly class DoublesCallRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->name->toString() !== 'callsTo') {
            return [];
        }

        $receiver = $scope->getType($node->var);

        if (!new ObjectType(Doubles::class)->isSuperTypeOf($receiver)->yes()) {
            return [];
        }

        $double = $this->argument($node, 'double', 0);
        $method = $this->argument($node, 'method', 1);

        if (!$double instanceof Arg || !$method instanceof Arg) {
            return [];
        }

        $doubleType = $scope->getType($double->value);

        if (!$doubleType->isObject()->yes()) {
            return [];
        }

        $classes = $doubleType->getObjectClassReflections();

        if (\count($classes) !== 1) {
            return [];
        }

        $errors = [];

        foreach ($scope->getType($method->value)->getConstantStrings() as $methodName) {
            $name = $methodName->getValue();

            if ($classes[0]->hasNativeMethod($name)) {
                continue;
            }

            $errors[] = $this->error(
                $name,
                $doubleType->describe(VerbosityLevel::typeOnly()),
                $node->getStartLine(),
            );
        }

        return $errors;
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

    private function error(string $method, string $type, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf(
            'callsTo() cannot inspect "%s()" on doubled type "%s" because the method does not exist.',
            $method,
            $type,
        ))
            ->identifier('greenlight.doubles.callsToMethod')
            ->line($line)
            ->build();
    }
}

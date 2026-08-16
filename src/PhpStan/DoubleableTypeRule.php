<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Doubles\Doubles;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Checks that a known type can have a double proxy.
 *
 * @internal
 *
 * @implements Rule<MethodCall>
 */
final readonly class DoubleableTypeRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier
            || !\in_array($node->name->toString(), ['mock', 'stub', 'spy'], true)
        ) {
            return [];
        }

        if (!new ObjectType(Doubles::class)->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        $type = $this->argument($node, 'type', 0);

        if (!$type instanceof Arg) {
            return [];
        }

        $errors = [];

        foreach ($scope->getType($type->value)->getClassStringObjectType()->getObjectClassReflections() as $class) {
            $reason = $this->unsupportedReason($class);

            if ($reason === null) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(\sprintf(
                'Doubles::%s() cannot double %s because %s.',
                $node->name->toString(),
                $class->getDisplayName(),
                $reason,
            ))
                ->identifier('greenlight.doubles.doubleableType')
                ->line($node->getStartLine())
                ->build();
        }

        return $errors;
    }

    private function unsupportedReason(ClassReflection $class): ?string
    {
        if ($class->isInterface()) {
            return null;
        }

        if ($class->isEnum()) {
            return 'it is an enum. Use an interface that the enum implements';
        }

        if ($class->isReadOnly()) {
            return 'it is a readonly class. Use an interface instead';
        }

        if ($class->isFinal()) {
            return 'it is final. Use an interface instead';
        }

        if ($class->isTrait()) {
            return 'it is a trait. Use a class or interface that uses it';
        }

        return null;
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
}

<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Attribute\Test;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeCombinator;

/**
 * Checks whether Greenlight can construct a class that contains tests.
 *
 * @implements Rule<InClassNode>
 */
final class TestConstructorRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if ($class->isAnonymous()
            || $class->isAbstract()
            || $class->isInterface()
            || $class->isTrait()
            || $class->isEnum()
            || !$this->containsTest($class)
        ) {
            return [];
        }

        if (!$class->hasConstructor()) {
            return [];
        }

        $constructor = $class->getConstructor();
        $variants = $constructor->getVariants();

        if (\count($variants) !== 1) {
            return [];
        }

        $line = $node->getStartLine();
        $errors = [];

        if (!$constructor->isPublic()) {
            $errors[] = $this->error(
                \sprintf('Test class %s cannot be instantiated because its constructor is not public.', $class->getDisplayName()),
                'visibility',
                $line,
            );
        }

        foreach ($variants[0]->getParameters() as $parameter) {
            if ($parameter->isOptional() && !$parameter->isVariadic()) {
                continue;
            }

            $type = TypeCombinator::removeNull($parameter->getNativeType());

            if ($type->isObject()->yes() && \count($type->getObjectClassNames()) === 1) {
                continue;
            }

            $errors[] = $this->error(
                \sprintf(
                    'Constructor parameter $%s of test class %s cannot be resolved. '
                    . 'Use one class or interface type, or give the parameter a default value.',
                    $parameter->getName(),
                    $class->getDisplayName(),
                ),
                'parameter',
                $line,
            );
        }

        return $errors;
    }

    private function containsTest(ClassReflection $class): bool
    {
        foreach ($class->getNativeReflection()->getMethods() as $method) {
            foreach ($class->getNativeMethod($method->getName())->getAttributes() as $attribute) {
                if ($attribute->getName() === Test::class) {
                    return true;
                }
            }
        }

        return false;
    }

    private function error(string $message, string $identifier, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('greenlight.testConstructor.' . $identifier)
            ->line($line)
            ->build();
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Attribute\Test;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A test method must be public, non-static, and concrete.
 *
 * @implements Rule<InClassMethodNode>
 */
final class TestMethodRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $method = $node->getOriginalNode();
        $line = null;

        foreach ($method->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($scope->resolveName($attribute->name) === Test::class) {
                    $line = $attribute->getStartLine();

                    break 2;
                }
            }
        }

        if ($line === null) {
            return [];
        }

        $methodName = \sprintf(
            '%s::%s()',
            $node->getClassReflection()->getDisplayName(),
            $method->name->toString(),
        );
        $errors = [];

        if (!$method->isPublic()) {
            $errors[] = $this->error(
                \sprintf('Test method %s cannot run because it is not public.', $methodName),
                'visibility',
                $line,
            );
        }

        if ($method->isStatic()) {
            $errors[] = $this->error(
                \sprintf('Test method %s cannot run because it is static.', $methodName),
                'static',
                $line,
            );
        }

        if ($method->isAbstract()) {
            $errors[] = $this->error(
                \sprintf('Test method %s cannot run because it is abstract.', $methodName),
                'abstract',
                $line,
            );
        }

        return $errors;
    }

    private function error(string $message, string $identifier, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('greenlight.testMethod.' . $identifier)
            ->line($line)
            ->build();
    }
}

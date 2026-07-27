<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * A lifecycle hook must be public, non-static, concrete, and callable without
 * arguments.
 *
 * @implements Rule<InClassMethodNode>
 */
final class LifecycleMethodRule implements Rule
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
                $name = $scope->resolveName($attribute->name);

                if ($name === Before::class || $name === After::class) {
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
                \sprintf('Lifecycle hook %s cannot run because it is not public.', $methodName),
                'visibility',
                $line,
            );
        }

        if ($method->isStatic()) {
            $errors[] = $this->error(
                \sprintf('Lifecycle hook %s cannot run because it is static.', $methodName),
                'static',
                $line,
            );
        }

        if ($method->isAbstract()) {
            $errors[] = $this->error(
                \sprintf('Lifecycle hook %s cannot run because it is abstract.', $methodName),
                'abstract',
                $line,
            );
        }

        if ($this->hasRequiredParameter($method)) {
            $errors[] = $this->error(
                \sprintf('Lifecycle hook %s must accept zero arguments.', $methodName),
                'parameters',
                $line,
            );
        }

        return $errors;
    }

    private function hasRequiredParameter(Node\Stmt\ClassMethod $method): bool
    {
        return \array_any(
            $method->params,
            static fn(Node\Param $parameter): bool => !$parameter->default instanceof Node\Expr && !$parameter->variadic,
        );
    }

    private function error(string $message, string $identifier, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('greenlight.lifecycleMethod.' . $identifier)
            ->line($line)
            ->build();
    }
}

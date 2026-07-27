<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Attribute\SkipUnless;
use Greenlight\Core\Condition;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Name\FullyQualified;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\Rule;

/**
 * Checks #[SkipUnless] arguments against the referenced condition constructor.
 *
 * @implements Rule<Attribute>
 */
final readonly class SkipUnlessConditionRule implements Rule
{
    public function __construct(private ReflectionProvider $reflectionProvider) {}

    #[\Override]
    public function getNodeType(): string
    {
        return Attribute::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if ($scope->resolveName($node->name) !== SkipUnless::class) {
            return [];
        }

        $condition = $this->conditionArgument($node);

        if (!$condition instanceof Node\Expr) {
            return [];
        }

        $classes = $scope->getType($condition)->getConstantStrings();

        if (\count($classes) !== 1) {
            return [];
        }

        $class = $classes[0]->getValue();

        if (!$this->reflectionProvider->hasClass($class)
            || !$this->reflectionProvider->getClass($class)->implementsInterface(Condition::class)
        ) {
            return [];
        }

        $scope->invokeNodeCallback(new New_(
            new FullyQualified($class),
            $this->conditionArguments($node),
            $node->getAttributes(),
        ));

        return [];
    }

    private function conditionArgument(Attribute $attribute): ?Node\Expr
    {
        foreach ($attribute->args as $index => $argument) {
            if ($argument->name === null ? $index === 0 : $argument->name->toString() === 'condition') {
                return $argument->value;
            }
        }

        return null;
    }

    /**
     * Greenlight removes variadic argument names before it calls the
     * condition constructor. Thus, the synthetic call uses positional
     * arguments in declaration order.
     *
     * @return list<Arg>
     */
    private function conditionArguments(Attribute $attribute): array
    {
        $arguments = [];

        foreach ($attribute->args as $index => $argument) {
            if ($argument->name === null ? $index === 0 : $argument->name->toString() === 'condition') {
                continue;
            }

            $arguments[] = new Arg(
                $argument->value,
                attributes: $argument->getAttributes(),
            );
        }

        return $arguments;
    }
}

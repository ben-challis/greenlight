<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Group;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\NoExpectations;
use Greenlight\Attribute\RequiresResource;
use Greenlight\Attribute\Retry;
use Greenlight\Attribute\Skip;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Attribute\Timeout;
use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Reports method attributes that have no effect without `#[Test]`.
 *
 * @implements Rule<InClassMethodNode>
 */
final class TestAttributePlacementRule implements Rule
{
    /**
     * @var array<class-string, non-empty-string>
     */
    private const array TEST_ATTRIBUTES = [
        DataRow::class => 'DataRow',
        DataSet::class => 'DataSet',
        Group::class => 'Group',
        Isolated::class => 'Isolated',
        NoExpectations::class => 'NoExpectations',
        RequiresResource::class => 'RequiresResource',
        Retry::class => 'Retry',
        Skip::class => 'Skip',
        SkipUnless::class => 'SkipUnless',
        Timeout::class => 'Timeout',
    ];

    #[\Override]
    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        $attributes = [];
        $hasTest = false;

        foreach ($node->getOriginalNode()->attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                $name = $scope->resolveName($attribute->name);

                if ($name === Test::class) {
                    $hasTest = true;
                }

                if (isset(self::TEST_ATTRIBUTES[$name])) {
                    $attributes[] = [$name, $attribute->getStartLine()];
                }
            }
        }

        if ($hasTest || $attributes === []) {
            return [];
        }

        $method = \sprintf(
            '%s::%s()',
            $node->getClassReflection()->getDisplayName(),
            $node->getMethodReflection()->getName(),
        );
        $errors = [];

        foreach ($attributes as [$attribute, $line]) {
            $errors[] = $this->error(\sprintf(
                '#[%s] on %s has no effect because the method is not a #[Test].',
                self::TEST_ATTRIBUTES[$attribute],
                $method,
            ), $line);
        }

        return $errors;
    }

    private function error(string $message, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)
            ->identifier('greenlight.testAttribute.noEffect')
            ->line($line)
            ->build();
    }
}

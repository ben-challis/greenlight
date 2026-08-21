<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Config\ConfigFileError;
use Greenlight\Config\InvalidConfiguration;
use Greenlight\Expect\Expectation;
use Greenlight\Expect\TemporalExpectation;
use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;

/**
 * Checks that an extension matcher has a boolean return type.
 *
 * @internal
 *
 * @implements Rule<MethodCall>
 */
final readonly class ExtensionMatcherReturnRule implements Rule
{
    public function __construct(private MatcherMapProvider $matcherMap) {}

    #[\Override]
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    /**
     * @throws ConfigFileError
     * @throws InvalidConfiguration
     * @throws MatcherMapError
     */
    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $name = $node->name->toString();
        $map = $this->matcherMap->get();

        if (!$map->has($name) || !$this->isExpectation($scope, $node)) {
            return [];
        }

        $returnType = $map->returnType($name);

        if (!$returnType instanceof \ReflectionType || self::isCompatibleReturn($returnType)) {
            return [];
        }

        return [$this->error($name, MatcherMap::typeName($returnType), $node->getStartLine())];
    }

    private static function isCompatibleReturn(\ReflectionType $type): bool
    {
        if ($type instanceof \ReflectionUnionType || $type instanceof \ReflectionIntersectionType) {
            return \array_all($type->getTypes(), self::isCompatibleReturn(...));
        }

        return $type instanceof \ReflectionNamedType
            && \in_array($type->getName(), ['bool', 'true', 'false', 'mixed', 'never'], true)
            && (!$type->allowsNull() || $type->getName() === 'mixed');
    }

    private function isExpectation(Scope $scope, MethodCall $call): bool
    {
        $receiver = $scope->getType($call->var);

        return new ObjectType(Expectation::class)->isSuperTypeOf($receiver)->yes()
            || new ObjectType(TemporalExpectation::class)->isSuperTypeOf($receiver)->yes();
    }

    private function error(string $matcher, string $returnType, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf(
            'Extension matcher %s() must return bool, but its declared return type is %s.',
            $matcher,
            $returnType,
        ))
            ->identifier('greenlight.extensionMatcher.returnType')
            ->line($line)
            ->build();
    }
}

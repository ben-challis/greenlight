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
use PHPStan\Type\ErrorType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\VerbosityLevel;

/**
 * Checks the chain subject against an extension matcher's first parameter.
 *
 * @internal
 *
 * @implements Rule<MethodCall>
 */
final readonly class ExtensionMatcherSubjectRule implements Rule
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
     */
    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $name = $node->name->toString();
        $map = $this->matcherMap->get();

        if (!$map->has($name)) {
            return [];
        }

        $receiver = $scope->getType($node->var);
        $subject = $this->subjectType($receiver);

        if (!$subject instanceof Type || $subject instanceof ErrorType || $subject instanceof MixedType) {
            return [];
        }

        $subjectParameter = $map->subjectParameter($name);
        $accepted = NativeType::fromReflection($subjectParameter?->getType());

        if ($accepted instanceof MixedType || !$accepted->accepts($subject, $scope->isDeclareStrictTypes())->no()) {
            return [];
        }

        return [$this->error($name, $accepted, $subject, $node->getStartLine())];
    }

    private function subjectType(Type $receiver): ?Type
    {
        if (new ObjectType(Expectation::class)->isSuperTypeOf($receiver)->yes()) {
            return $receiver->getTemplateType(Expectation::class, 'T');
        }

        if (new ObjectType(TemporalExpectation::class)->isSuperTypeOf($receiver)->yes()) {
            return $receiver->getTemplateType(TemporalExpectation::class, 'T');
        }

        return null;
    }

    private function error(string $matcher, Type $accepted, Type $subject, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf(
            'Extension matcher %s() requires subject type %s, but the subject has type %s.',
            $matcher,
            $accepted->describe(VerbosityLevel::typeOnly()),
            $subject->describe(VerbosityLevel::typeOnly()),
        ))
            ->identifier('greenlight.extensionMatcher.subjectType')
            ->line($line)
            ->build();
    }
}

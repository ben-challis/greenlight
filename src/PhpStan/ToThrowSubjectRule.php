<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Expect\ConsistentlyExpectation;
use Greenlight\Expect\EventuallyExpectation;
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
 * Checks that `toThrow()` has a callable subject.
 *
 * @internal
 *
 * @implements Rule<MethodCall>
 */
final class ToThrowSubjectRule implements Rule
{
    #[\Override]
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier || $node->name->toString() !== 'toThrow') {
            return [];
        }

        $subject = $this->subjectType($scope->getType($node->var));

        if (!$subject instanceof Type || $subject instanceof ErrorType || $subject instanceof MixedType) {
            return [];
        }

        if ($subject->isCallable()->yes()) {
            return [];
        }

        return [$this->error($subject, $node->getStartLine())];
    }

    private function subjectType(Type $receiver): ?Type
    {
        foreach ([
            Expectation::class,
            EventuallyExpectation::class,
            ConsistentlyExpectation::class,
            TemporalExpectation::class,
        ] as $class) {
            if (new ObjectType($class)->isSuperTypeOf($receiver)->yes()) {
                return $receiver->getTemplateType($class, 'T');
            }
        }

        return null;
    }

    private function error(Type $subject, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf(
            'toThrow() requires a callable subject. The subject type is %s.',
            $subject->describe(VerbosityLevel::typeOnly()),
        ))
            ->identifier('greenlight.toThrow.subjectType')
            ->line($line)
            ->build();
    }
}

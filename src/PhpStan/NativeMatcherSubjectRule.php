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
use PHPStan\Type\ArrayType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IterableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\StringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;

/**
 * Checks the subject types for native matchers that have type requirements.
 *
 * @internal
 *
 * @implements Rule<MethodCall>
 */
final class NativeMatcherSubjectRule implements Rule
{
    /**
     * @var array<non-empty-string, non-empty-string>
     */
    private const array REQUIREMENTS = [
        'toContain' => 'a string or iterable',
        'toHaveCount' => 'a countable or traversable',
        'toBeEmpty' => 'a string, array, Countable, or iterable',
        'toHaveLength' => 'a string, array, or Countable',
        'toHaveKey' => 'an array or ArrayAccess',
        'toContainSubset' => 'an array',
        'toBeGreaterThan' => 'an int or float',
        'toBeGreaterThanOrEqual' => 'an int or float',
        'toBeLessThan' => 'an int or float',
        'toBeLessThanOrEqual' => 'an int or float',
        'toBeWithin' => 'an int or float',
        'toMatch' => 'a string',
        'toStartWith' => 'a string',
        'toEndWith' => 'a string',
        'toBeJson' => 'a string',
        'toMatchJson' => 'a string',
    ];

    #[\Override]
    public function getNodeType(): string
    {
        return MethodCall::class;
    }

    #[\Override]
    public function processNode(Node $node, Scope $scope): array
    {
        if (!$node->name instanceof Identifier) {
            return [];
        }

        $matcher = $node->name->toString();

        if (!isset(self::REQUIREMENTS[$matcher])) {
            return [];
        }

        $subject = $this->subjectType($scope->getType($node->var));

        if (!$subject instanceof Type || $subject instanceof ErrorType || $subject instanceof MixedType) {
            return [];
        }

        $accepted = $this->acceptedType($matcher);

        if (!$accepted->accepts($subject, $scope->isDeclareStrictTypes())->no()) {
            return $this->needleErrors($matcher, $subject, $node, $scope);
        }

        return [$this->subjectError($matcher, $subject, $node->getStartLine())];
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

    private function acceptedType(string $matcher): Type
    {
        $mixed = new MixedType();
        $array = new ArrayType($mixed, $mixed);
        $iterable = new IterableType($mixed, $mixed);

        return match ($matcher) {
            'toContain' => TypeCombinator::union(new StringType(), $iterable),
            'toHaveCount' => TypeCombinator::union($iterable, new ObjectType(\Countable::class)),
            'toBeEmpty' => TypeCombinator::union(new StringType(), $iterable, new ObjectType(\Countable::class)),
            'toHaveLength' => TypeCombinator::union(new StringType(), $array, new ObjectType(\Countable::class)),
            'toHaveKey' => TypeCombinator::union($array, new ObjectType(\ArrayAccess::class)),
            'toContainSubset' => $array,
            'toBeGreaterThan',
            'toBeGreaterThanOrEqual',
            'toBeLessThan',
            'toBeLessThanOrEqual',
            'toBeWithin' => TypeCombinator::union(new IntegerType(), new FloatType()),
            'toMatch',
            'toStartWith',
            'toEndWith',
            'toBeJson',
            'toMatchJson' => new StringType(),
            default => throw new \LogicException(\sprintf(
                'Native matcher "%s" has no subject type requirement.',
                $matcher,
            )),
        };
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function needleErrors(string $matcher, Type $subject, MethodCall $call, Scope $scope): array
    {
        if ($matcher !== 'toContain' || !new StringType()->isSuperTypeOf($subject)->yes()) {
            return [];
        }

        $argument = $call->getArgs()[0] ?? null;

        if (!$argument instanceof Node\Arg) {
            return [];
        }

        $needle = $scope->getType($argument->value);

        if ($argument->unpack) {
            $needle = $needle->getIterableValueType();
        }

        if ($needle instanceof ErrorType || $needle instanceof MixedType || !new StringType()->accepts($needle, true)->no()) {
            return [];
        }

        return [RuleErrorBuilder::message(\sprintf(
            'toContain() requires a string needle for a string subject. The needle type is %s.',
            $needle->describe(VerbosityLevel::typeOnly()),
        ))
            ->identifier('greenlight.toContain.needleType')
            ->line($call->getStartLine())
            ->build()];
    }

    private function subjectError(string $matcher, Type $subject, int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message(\sprintf(
            '%s() requires %s subject. The subject type is %s.',
            $matcher,
            self::REQUIREMENTS[$matcher],
            $subject->describe(VerbosityLevel::typeOnly()),
        ))
            ->identifier('greenlight.nativeMatcher.subjectType')
            ->line($line)
            ->build();
    }
}

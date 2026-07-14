<?php

declare(strict_types=1);

namespace Greenlight\PhpStan;

use Greenlight\Expect\Expectation;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;

/**
 * The toThrow() signature keeps matching and message nullable for backwards
 * compatibility, so their mutual exclusivity needs a call-site rule.
 *
 * @implements Rule<MethodCall>
 */
final class ToThrowMessageRule implements Rule
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

        if (!new ObjectType(Expectation::class)->isSuperTypeOf($scope->getType($node->var))->yes()) {
            return [];
        }

        foreach ($this->constraintStates($node, $scope) as $state) {
            if ($state['matching'] instanceof Type
                && $state['message'] instanceof Type
                && !$state['matching']->isNull()->yes()
                && !$state['message']->isNull()->yes()
            ) {
                return [$this->error($node->getStartLine())];
            }
        }

        return [];
    }

    /**
     * Expands constant-array argument unpacking into its possible call shapes.
     * Dynamic unpacks remain a runtime concern.
     *
     * @return list<array{matching: ?Type, message: ?Type, nextPosition: int}>
     */
    private function constraintStates(MethodCall $call, Scope $scope): array
    {
        $states = [[
            'matching' => null,
            'message' => null,
            'nextPosition' => 0,
        ]];

        foreach ($call->args as $argument) {
            if (!$argument instanceof Arg) {
                continue;
            }

            if (!$argument->unpack) {
                foreach ($states as $index => $state) {
                    $states[$index] = $this->withArgument(
                        $state,
                        $scope->getType($argument->value),
                        $argument->name instanceof Identifier ? $argument->name->toString() : null,
                    );
                }

                continue;
            }

            $constantArrays = $scope->getType($argument->value)->getConstantArrays();

            if ($constantArrays === []) {
                continue;
            }

            $expanded = [];

            foreach ($states as $state) {
                foreach ($constantArrays as $constantArray) {
                    $candidate = $state;

                    foreach ($constantArray->getKeyTypes() as $index => $keyType) {
                        $key = $keyType->getValue();
                        $candidate = $this->withArgument(
                            $candidate,
                            $constantArray->getValueTypes()[$index],
                            \is_string($key) ? $key : null,
                        );
                    }

                    $expanded[] = $candidate;
                }
            }

            $states = $expanded;
        }

        return $states;
    }

    /**
     * @param array{matching: ?Type, message: ?Type, nextPosition: int} $state
     *
     * @return array{matching: ?Type, message: ?Type, nextPosition: int}
     */
    private function withArgument(array $state, Type $type, ?string $name): array
    {
        if ($name === 'matching' || ($name === null && $state['nextPosition'] === 1)) {
            $state['matching'] = $type;
        } elseif ($name === 'message' || ($name === null && $state['nextPosition'] === 2)) {
            $state['message'] = $type;
        }

        if ($name === null) {
            ++$state['nextPosition'];
        }

        return $state;
    }

    private function error(int $line): IdentifierRuleError
    {
        return RuleErrorBuilder::message('toThrow() accepts either matching: or message:, not both.')
            ->identifier('greenlight.toThrow.messageConstraint')
            ->line($line)
            ->build();
    }
}

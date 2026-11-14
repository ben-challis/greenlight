<?php

declare(strict_types=1);

namespace Greenlight\Rector;

use Greenlight\Expect\Expect;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;

/**
 * Plans the rewrite of an `expectException()` block to a `toThrow()`
 * expectation.
 *
 * `plan()` validates the block and builds the replacement statements in one
 * call. It does not change the method. The PHPUnit message checks are
 * substring or regex checks, so both convert to the `matching:` constraint
 * and never to the exact `message:` constraint.
 *
 * @internal
 */
final class ThrowBlockPlanner
{
    /**
     * Validates the expectException block. The calls must sit consecutively
     * at the top level. The method body ends with one expression statement,
     * and that statement becomes the toThrow() subject.
     *
     * @param array<int, true> $sanctioned object IDs of the calls that the
     *   rewrite removes
     *
     * @return list<Stmt>|false|null the replacement statements, false for a
     *   block that rejects the class, or null without a block
     */
    public function plan(ClassMethod $method, array &$sanctioned): array|false|null
    {
        $stmts = \array_values($method->stmts ?? []);
        $found = [];

        foreach ($stmts as $index => $stmt) {
            $call = $stmt instanceof Expression ? $stmt->expr : null;

            if (!$call instanceof MethodCall && !$call instanceof StaticCall) {
                continue;
            }

            if (!OwnCallClassifier::isSelfReceiver($call)) {
                continue;
            }

            $name = $call->name instanceof Identifier ? $call->name->toLowerString() : null;

            if ($name !== null && \in_array($name, OwnCallClassifier::EXPECT_EXCEPTION_METHODS, true)) {
                $found[$index] = [$name, $call];
            }
        }

        if ($found === []) {
            return null;
        }

        $indices = \array_keys($found);
        $first = \min($indices);

        if ($indices !== \range($first, \max($indices))) {
            return false;
        }

        $act = $stmts[\count($stmts) - 1];

        if (\max($indices) !== \count($stmts) - 2 || !$act instanceof Expression) {
            return false;
        }

        $exception = null;
        $matching = null;

        foreach ($found as [$name, $call]) {
            $arguments = OwnCallClassifier::positionalArgs($call->args);

            if ($arguments === null || \count($arguments) !== 1) {
                return false;
            }

            $value = $arguments[0]->value;

            if ($name === 'expectexception') {
                if ($exception instanceof Expr) {
                    return false;
                }

                $exception = $value;

                continue;
            }

            if ($matching instanceof Expr) {
                return false;
            }

            if ($name === 'expectexceptionmessage') {
                // PHPUnit checks the message for a substring. The toThrow()
                // message constraint is exact, so the rule writes a quoted
                // regex.
                if (!$value instanceof String_) {
                    return false;
                }

                $matching = new String_('/' . \preg_quote($value->value, '/') . '/');

                continue;
            }

            $matching = $value;
        }

        foreach ($found as [, $call]) {
            $sanctioned[\spl_object_id($call)] = true;
        }

        return [
            ...\array_slice($stmts, 0, $first),
            new Expression($this->expectation($act->expr, $exception, $matching)),
        ];
    }

    private function expectation(Expr $act, ?Expr $exception, ?Expr $matching): MethodCall
    {
        $finder = new NodeFinder();
        $usesThis = $finder->findFirst(
            [$act],
            static fn(Node $node): bool => $node instanceof Variable && $node->name === 'this',
        ) instanceof Node;

        $subject = new ArrowFunction([
            'static' => !$usesThis,
            'params' => [],
            'expr' => $act,
        ]);

        $toThrowArgs = [new Arg($exception ?? new ClassConstFetch(new FullyQualified('Throwable'), 'class'))];

        if ($matching instanceof Expr) {
            $toThrowArgs[] = new Arg($matching, name: new Identifier('matching'));
        }

        return new MethodCall(
            new StaticCall(new FullyQualified(Expect::class), 'calling', [new Arg($subject)]),
            'toThrow',
            $toThrowArgs,
        );
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Rector;

use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Test\SkipTest;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Throw_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\NodeTypeResolver;

/**
 * Classifies each call in a PHPUnit test class.
 *
 * `classify()` decides in one call. It returns the Greenlight replacement
 * for an assertion, `fail()`, or `markTestSkipped()` call. It returns null
 * for a call that stays as it is, and false for a call that rejects the
 * class.
 *
 * @internal
 */
final readonly class OwnCallClassifier
{
    public const string EXPECT_NO_ASSERTIONS = 'expectnottoperformassertions';

    /**
     * @var list<string>
     */
    public const array EXPECT_EXCEPTION_METHODS = [
        'expectexception',
        'expectexceptionmessage',
        'expectexceptionmessagematches',
    ];

    private const string ASSERT_CLASS = 'PHPUnit\Framework\Assert';

    private const string MARK_TEST_SKIPPED = 'marktestskipped';

    private const string FAIL = 'fail';

    public function __construct(
        private NodeNameResolver $names,
        private NodeTypeResolver $types,
    ) {}

    /**
     * @param array<string, true> $defined lowercase names of the class methods
     * @param array<int, true> $sanctioned object IDs of calls that other
     *   rewrites remove
     */
    public function classify(
        Node $node,
        Class_ $class,
        array $defined,
        array $sanctioned,
        bool $dropAssertionMessages,
    ): Expr|false|null {
        if ($node instanceof FuncCall) {
            $name = $node->name instanceof Name ? $this->names->getName($node->name) : null;

            return $name !== null && \str_starts_with($name, 'PHPUnit\\') ? false : null;
        }

        if ($node instanceof NullsafeMethodCall) {
            return $this->isOwnInstanceReceiver($node->var, $class) ? false : null;
        }

        if ($node instanceof MethodCall) {
            if (!$this->isOwnInstanceReceiver($node->var, $class)) {
                return null;
            }

            return $this->convertOwnCall($node, $defined, $sanctioned, $dropAssertionMessages, allowInstanceApi: true);
        }

        if (!$node instanceof StaticCall) {
            return null;
        }

        return match ($this->staticCallTarget($node, $class)) {
            'phpunit' => false,
            'parent' => isset($sanctioned[\spl_object_id($node)]) ? null : false,
            'own' => $this->convertOwnCall($node, $defined, $sanctioned, $dropAssertionMessages, allowInstanceApi: true),
            'assert' => $this->convertOwnCall($node, $defined, $sanctioned, $dropAssertionMessages, allowInstanceApi: false),
            'other' => null,
        };
    }

    public static function isSelfReceiver(MethodCall|StaticCall $call): bool
    {
        if ($call instanceof MethodCall) {
            return $call->var instanceof Variable && $call->var->name === 'this';
        }

        return $call->class instanceof Name
            && \in_array($call->class->toLowerString(), ['self', 'static'], true);
    }

    /**
     * @param array<Node> $args
     *
     * @return list<Arg>|null Null if an argument is named, unpacked, or a
     *   first-class callable placeholder
     */
    public static function positionalArgs(array $args): ?array
    {
        $positional = [];

        foreach ($args as $argument) {
            if (!$argument instanceof Arg || $argument->name instanceof Identifier || $argument->unpack) {
                return null;
            }

            $positional[] = $argument;
        }

        return $positional;
    }

    /**
     * Converts a call to the inherited TestCase or Assert API. With
     * `$allowInstanceApi` false, only static Assert methods convert.
     *
     * @param array<string, true> $defined
     * @param array<int, true> $sanctioned
     */
    private function convertOwnCall(
        MethodCall|StaticCall $call,
        array $defined,
        array $sanctioned,
        bool $dropAssertionMessages,
        bool $allowInstanceApi,
    ): Expr|false|null {
        $name = $call->name instanceof Identifier ? $call->name->toLowerString() : null;

        if ($name === null) {
            return false;
        }

        if ($allowInstanceApi && isset($defined[$name])) {
            return null;
        }

        $arguments = self::positionalArgs($call->args);
        $conversion = AssertionMap::lookup($name);

        if ($conversion instanceof AssertionConversion) {
            if ($arguments === null || \count($arguments) < $conversion->arity) {
                return false;
            }

            if (\count($arguments) !== $conversion->arity && !$dropAssertionMessages) {
                return false;
            }

            if (!$this->argumentOrderConverts($conversion, $arguments) || !$this->looseNegationConverts($name, $arguments)) {
                return false;
            }

            return $this->buildExpectation($conversion, $arguments);
        }

        if ($name === self::FAIL) {
            if ($arguments === null || \count($arguments) > 1) {
                return false;
            }

            return new StaticCall(
                new FullyQualified(Fail::class),
                'because',
                [$arguments[0] ?? new Arg(new String_('Test failed.'))],
            );
        }

        if (!$allowInstanceApi) {
            return false;
        }

        if ($name === self::MARK_TEST_SKIPPED) {
            return $this->convertMarkTestSkipped($arguments);
        }

        if (\in_array($name, self::EXPECT_EXCEPTION_METHODS, true) || $name === self::EXPECT_NO_ASSERTIONS) {
            return isset($sanctioned[\spl_object_id($call)]) ? null : false;
        }

        return false;
    }

    /**
     * @param list<Arg>|null $arguments
     */
    private function convertMarkTestSkipped(?array $arguments): Throw_|false
    {
        if ($arguments === null || \count($arguments) > 1) {
            return false;
        }

        $reason = $arguments[0]->value ?? null;

        if ($reason instanceof Expr && !$reason instanceof String_
            && !$this->types->getType($reason)->isNonEmptyString()->yes()
        ) {
            return false;
        }

        // SkipTest requires a non-empty reason. The PHPUnit argument is optional.
        $emptyReason = $reason === null || ($reason instanceof String_ && $reason->value === '');
        $replacementArguments = $emptyReason ? [new Arg(new String_('Skipped.'))] : $arguments;

        return new Throw_(new New_(new FullyQualified(SkipTest::class), $replacementArguments));
    }

    /**
     * PHPUnit compares loosely in assertNotEquals() and
     * assertNotEqualsCanonicalizing(). Greenlight compares strictly, so the
     * negated matcher accepts more values, for example `false` and `0`. The
     * negation converts only when both operands have one proven value kind
     * that both frameworks compare in the same way.
     *
     * @param list<Arg> $arguments
     */
    private function looseNegationConverts(string $name, array $arguments): bool
    {
        if ($name !== 'assertnotequals' && $name !== 'assertnotequalscanonicalizing') {
            return true;
        }

        $expected = $this->strictKind($arguments[0]->value);
        $actual = $this->strictKind($arguments[1]->value);

        if ($expected === null || $expected !== $actual) {
            return false;
        }

        if ($name === 'assertnotequals') {
            return true;
        }

        // PHPUnit canonicalization sorts each array and drops its keys.
        // Greenlight keeps the keys of an associative array.
        return \substr_count($expected, 'array:') === 1
            && $this->types->getType($arguments[0]->value)->isList()->yes()
            && $this->types->getType($arguments[1]->value)->isList()->yes();
    }

    /**
     * Describes a value type that PHPUnit and Greenlight compare in the
     * same way: one scalar type, or arrays whose values have one such type.
     * Both frameworks pair array values by key and ignore key order.
     *
     * @return non-empty-string|null
     */
    private function strictKind(Expr $expression): ?string
    {
        $type = $this->types->getType($expression);
        $prefix = '';

        for ($depth = 0; $depth < 8; $depth++) {
            $kind = match (true) {
                $type->isInteger()->yes() => 'int',
                $type->isFloat()->yes() => 'float',
                $type->isString()->yes() => 'string',
                $type->isBoolean()->yes() => 'bool',
                $type->isNull()->yes() => 'null',
                default => null,
            };

            if ($kind !== null) {
                return $prefix . $kind;
            }

            if (!$type->isArray()->yes()) {
                return null;
            }

            $prefix .= 'array:';
            $type = $type->getIterableValueType();
        }

        return null;
    }

    /**
     * @param list<Arg> $arguments
     */
    private function argumentOrderConverts(AssertionConversion $conversion, array $arguments): bool
    {
        $order = [$conversion->subject, ...$conversion->matcherArguments];

        foreach ($order as $position => $index) {
            foreach (\array_slice($order, $position + 1) as $laterIndex) {
                if ($index < $laterIndex) {
                    continue;
                }

                $first = $arguments[$index]->value;
                $second = $arguments[$laterIndex]->value;

                if ($this->isLiteralValue($first) || $this->isLiteralValue($second)) {
                    continue;
                }

                if ($first instanceof Variable && \is_string($first->name)
                    && $second instanceof Variable && \is_string($second->name)
                ) {
                    continue;
                }

                // Calls and property hooks can change the other argument.
                // Keep the PHPUnit class when their order cannot stay safe.
                return false;
            }
        }

        return true;
    }

    private function isLiteralValue(Expr $expression): bool
    {
        if ($expression instanceof String_ || $expression instanceof Node\Scalar\Int_
            || $expression instanceof Node\Scalar\Float_ || $expression instanceof Node\Scalar\MagicConst
        ) {
            return true;
        }

        if ($expression instanceof Expr\ConstFetch) {
            return \in_array($expression->name->toLowerString(), ['true', 'false', 'null'], true);
        }

        if ($expression instanceof ClassConstFetch) {
            return $expression->class instanceof Name
                && $expression->name instanceof Identifier
                && $expression->name->toLowerString() === 'class';
        }

        if ($expression instanceof Expr\UnaryMinus || $expression instanceof Expr\UnaryPlus) {
            return $this->isLiteralValue($expression->expr);
        }

        if (!$expression instanceof Expr\Array_) {
            return false;
        }

        return \array_all($expression->items, fn(?ArrayItem $item): bool => $item instanceof ArrayItem
            && !$item->byRef && !$item->unpack
            && (!$item->key instanceof Expr || $this->isLiteralValue($item->key))
            && $this->isLiteralValue($item->value));
    }

    private function isOwnInstanceReceiver(Expr $receiver, Class_ $class): bool
    {
        if ($receiver instanceof Variable && $receiver->name === 'this') {
            return true;
        }

        return \in_array($this->classFqcn($class), $this->types->getType($receiver)->getObjectClassNames(), true);
    }

    /**
     * @return 'assert'|'other'|'own'|'parent'|'phpunit'
     */
    private function staticCallTarget(StaticCall $call, Class_ $class): string
    {
        if (!$call->class instanceof Name) {
            return $this->isOwnInstanceReceiver($call->class, $class) ? 'own' : 'other';
        }

        $raw = $call->class->toLowerString();

        if ($raw === 'parent') {
            return 'parent';
        }

        if ($raw === 'self' || $raw === 'static') {
            return 'own';
        }

        $resolved = $this->names->getName($call->class) ?? '';

        if ($resolved === $this->classFqcn($class)) {
            return 'own';
        }

        if ($resolved === self::ASSERT_CLASS) {
            return 'assert';
        }

        if (\str_starts_with($resolved, 'PHPUnit\\')) {
            return 'phpunit';
        }

        return 'other';
    }

    private function classFqcn(Class_ $class): string
    {
        if ($class->namespacedName instanceof Name) {
            return $class->namespacedName->toString();
        }

        return $class->name instanceof Identifier ? $class->name->toString() : '';
    }

    /**
     * @param list<Arg> $arguments
     */
    private function buildExpectation(AssertionConversion $conversion, array $arguments): MethodCall
    {
        $chain = new StaticCall(
            new FullyQualified(Expect::class),
            'value',
            [new Arg($arguments[$conversion->subject]->value)],
        );

        if ($conversion->negated) {
            $chain = new MethodCall($chain, 'not');
        }

        $matcherArguments = [];

        foreach ($conversion->matcherArguments as $index) {
            $matcherArguments[] = new Arg($arguments[$index]->value);
        }

        return new MethodCall($chain, $conversion->matcher, $matcherArguments);
    }
}

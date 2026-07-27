<?php

declare(strict_types=1);

namespace Greenlight\Rector;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Group;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\NoExpectations;
use Greenlight\Attribute\SkipUnless;
use Greenlight\Attribute\Test;
use Greenlight\Core\Test\SkipTest;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
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
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use Rector\Contract\Rector\ConfigurableRectorInterface;
use Rector\Rector\AbstractRector;
use Symplify\RuleDocGenerator\Contract\DocumentedRuleInterface;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\ConfiguredCodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

/**
 * Converts a final PHPUnit test class to Greenlight: the TestCase parent,
 * lifecycle hooks, attributes, assertions, and `expectException()` blocks. The
 * rule converts a class only when every member has a faithful Greenlight
 * equivalent. It does not change other classes.
 */
final class PhpUnitToGreenlightRector extends AbstractRector implements ConfigurableRectorInterface, DocumentedRuleInterface
{
    /**
     * Configuration key: remove PHPUnit failure-message arguments. Without
     * this option, a custom message rejects the class. Greenlight
     * expectations carry no custom message.
     */
    public const string DROP_ASSERTION_MESSAGES = 'drop_assertion_messages';

    private const string TEST_CASE_CLASS = 'PHPUnit\Framework\TestCase';

    private const string ASSERT_CLASS = 'PHPUnit\Framework\Assert';

    /**
     * TestCase template methods with no attribute-shaped Greenlight
     * equivalent. An override of one of these methods rejects the class.
     *
     * @var list<string>
     */
    private const array UNSUPPORTED_TEMPLATE_METHODS = [
        'setupbeforeclass',
        'teardownafterclass',
        'assertpreconditions',
        'assertpostconditions',
        'onnotsuccessfultest',
    ];

    /**
     * @var list<string>
     */
    private const array EXPECT_EXCEPTION_METHODS = [
        'expectexception',
        'expectexceptionmessage',
        'expectexceptionmessagematches',
    ];

    private const string EXPECT_NO_ASSERTIONS = 'expectnottoperformassertions';

    private const string MARK_TEST_SKIPPED = 'marktestskipped';

    private const string FAIL = 'fail';

    /**
     * Converted attributes that Greenlight does not permit more than once.
     *
     * @var list<class-string>
     */
    private const array SINGLE_GREENLIGHT_ATTRIBUTES = [
        DataSet::class,
        Isolated::class,
        NoExpectations::class,
        SkipUnless::class,
    ];

    private bool $dropAssertionMessages = false;

    /**
     * @param mixed[] $configuration
     */
    public function configure(array $configuration): void
    {
        $unknown = \array_diff_key($configuration, [self::DROP_ASSERTION_MESSAGES => true]);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(\sprintf(
                'Unknown configuration key "%s". The only supported key is "%s".',
                (string) \array_key_first($unknown),
                self::DROP_ASSERTION_MESSAGES,
            ));
        }

        $drop = $configuration[self::DROP_ASSERTION_MESSAGES] ?? false;

        if (!\is_bool($drop)) {
            throw new \InvalidArgumentException(\sprintf(
                'Configuration key "%s" expects a boolean.',
                self::DROP_ASSERTION_MESSAGES,
            ));
        }

        $this->dropAssertionMessages = $drop;
    }

    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Convert final PHPUnit test classes to Greenlight tests when every member has a faithful equivalent',
            [
                new ConfiguredCodeSample(
                    <<<'CODE_SAMPLE'
                    final class PriceTest extends \PHPUnit\Framework\TestCase
                    {
                        public function testFormatsTotals(): void
                        {
                            $this->assertSame('19.98', Price::fromString('9.99')->times(2)->format());
                        }
                    }
                    CODE_SAMPLE,
                    <<<'CODE_SAMPLE'
                    final class PriceTest
                    {
                        #[\Greenlight\Attribute\Test]
                        public function testFormatsTotals(): void
                        {
                            \Greenlight\Expect\Expect::that(Price::fromString('9.99')->times(2)->format())->toBe('19.98');
                        }
                    }
                    CODE_SAMPLE,
                    [self::DROP_ASSERTION_MESSAGES => false],
                ),
            ],
        );
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        return [Class_::class];
    }

    public function refactor(Node $node): ?Node
    {
        if (!$node instanceof Class_ || !$node->name instanceof Identifier || !$node->isFinal()) {
            return null;
        }

        if (!$node->extends instanceof Name || !$this->isName($node->extends, self::TEST_CASE_CLASS)) {
            return null;
        }

        $plan = $this->plan($node);

        if (!$plan instanceof ClassPlan) {
            return null;
        }

        $this->convert($node, $plan);

        return $node;
    }

    private function plan(Class_ $class): ?ClassPlan
    {
        if ($class->getTraitUses() !== [] || $class->getMethod('__construct') instanceof ClassMethod) {
            return null;
        }

        if (!$this->attributesConvert($class->attrGroups, onClass: true)) {
            return null;
        }

        $defined = $this->definedMethods($class);
        $hooks = [];
        $tests = [];
        $noExpectations = [];
        $throwRewrites = [];
        $sanctioned = [];

        foreach ($class->getMethods() as $method) {
            $name = $method->name->toLowerString();

            if (\in_array($name, self::UNSUPPORTED_TEMPLATE_METHODS, true)) {
                return null;
            }

            if (!$this->attributesConvert($method->attrGroups, onClass: false)) {
                return null;
            }

            $kind = $this->classify($method);

            if ($kind === null) {
                return null;
            }

            if ($kind === 'before' || $kind === 'after') {
                $hooks[$name] = $kind;
                $this->sanctionParentHookCalls($method, $sanctioned);

                continue;
            }

            if ($this->hasAttributeNamed($method->attrGroups, 'Override')) {
                // #[\Override] on anything but a hook points at the removed parent.
                return null;
            }

            if ($kind !== 'test') {
                continue;
            }

            $tests[] = $name;
            $rewrite = $this->planThrowRewrite($method, $sanctioned);

            if ($rewrite === false) {
                return null;
            }

            if ($rewrite instanceof ThrowRewrite) {
                $throwRewrites[$name] = $rewrite;
            }

            $noAssertions = $this->planNoAssertions($method, $sanctioned);

            if ($noAssertions === null) {
                return null;
            }

            if ($noAssertions) {
                $noExpectations[] = $name;
            }
        }

        if (!$this->dataProvidersConvert($class)) {
            return null;
        }

        if (!$this->callsConvert($class, $defined, $sanctioned)) {
            return null;
        }

        return new ClassPlan($hooks, $tests, $noExpectations, $throwRewrites);
    }

    private function convert(Class_ $class, ClassPlan $plan): void
    {
        $class->extends = null;
        $class->attrGroups = $this->convertAttributeGroups($class->attrGroups, dropOverride: false);

        foreach ($class->getMethods() as $method) {
            $name = $method->name->toLowerString();
            $hook = $plan->hooks[$name] ?? null;
            $method->attrGroups = $this->convertAttributeGroups($method->attrGroups, dropOverride: $hook !== null);

            if ($hook !== null) {
                $method->flags &= ~(Modifiers::PROTECTED | Modifiers::PRIVATE);
                $method->flags |= Modifiers::PUBLIC;
                $this->removeParentHookCalls($method);
                $this->prependAttribute($method, $hook === 'before' ? Before::class : After::class);
            }

            if (\in_array($name, $plan->tests, true)) {
                $rewrite = $plan->throwRewrites[$name] ?? null;

                if ($rewrite instanceof ThrowRewrite) {
                    $this->applyThrowRewrite($method, $rewrite);
                }

                if (\in_array($name, $plan->noExpectations, true)) {
                    $this->removeNoAssertionCalls($method);
                    $this->prependAttribute($method, NoExpectations::class);
                }

                $this->prependAttribute($method, Test::class);
            }
        }

        $defined = $this->definedMethods($class);
        $this->traverseNodesWithCallable($class->stmts, fn(Node $node): ?Node => $this->convertCall($node, $class, $defined));
    }

    /**
     * @return array<string, true>
     */
    private function definedMethods(Class_ $class): array
    {
        $defined = [];

        foreach ($class->getMethods() as $method) {
            $defined[$method->name->toLowerString()] = true;
        }

        return $defined;
    }

    /**
     * @return 'after'|'before'|'helper'|'test'|null
     */
    private function classify(ClassMethod $method): ?string
    {
        $name = $method->name->toLowerString();
        $isBefore = $name === 'setup' || $this->hasPhpUnitAttribute($method, 'Before');
        $isAfter = $name === 'teardown' || $this->hasPhpUnitAttribute($method, 'After');
        $hasTestAttribute = $this->hasPhpUnitAttribute($method, 'Test');
        $isTestNamed = \str_starts_with($name, 'test');

        if ($isBefore || $isAfter) {
            if ($isBefore && $isAfter) {
                return null;
            }

            if ($hasTestAttribute || $method->isStatic() || $method->isAbstract()) {
                return null;
            }

            return $isBefore ? 'before' : 'after';
        }

        $isTest = ($isTestNamed || $hasTestAttribute) && $method->isPublic() && !$method->isStatic();

        if ($isTest) {
            return 'test';
        }

        if ($hasTestAttribute) {
            // A #[Test] attribute on a static or non-public method is not a
            // runnable PHPUnit test. Without this check, conversion drops the
            // method without an error.
            return null;
        }

        return 'helper';
    }

    /**
     * @param array<AttributeGroup> $attrGroups
     */
    private function attributesConvert(array $attrGroups, bool $onClass): bool
    {
        $singleAttributes = [];

        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if (!$this->attributeConverts($attribute, $onClass)) {
                    return false;
                }

                $singleAttribute = $this->singleAttributeOutput($attribute);

                if ($singleAttribute === null) {
                    continue;
                }

                if (isset($singleAttributes[$singleAttribute])) {
                    return false;
                }

                $singleAttributes[$singleAttribute] = true;
            }
        }

        return true;
    }

    /**
     * Returns the Greenlight attribute when the conversion can emit it only
     * once on one declaration.
     *
     * @return class-string|null
     */
    private function singleAttributeOutput(Attribute $attribute): ?string
    {
        $resolved = $this->getName($attribute->name);

        if (\in_array($resolved, self::SINGLE_GREENLIGHT_ATTRIBUTES, true)) {
            return $resolved;
        }

        if (!\str_starts_with($resolved, PhpUnitAttributes::NAMESPACE_PREFIX)) {
            return null;
        }

        $short = \substr($resolved, \strlen(PhpUnitAttributes::NAMESPACE_PREFIX));

        if (isset(PhpUnitAttributes::SKIP_UNLESS_CONDITIONS[$short])) {
            return SkipUnless::class;
        }

        $renamed = PhpUnitAttributes::RENAMES[$short] ?? null;

        return \is_string($renamed) && \in_array($renamed, self::SINGLE_GREENLIGHT_ATTRIBUTES, true)
            ? $renamed
            : null;
    }

    private function attributeConverts(Attribute $attribute, bool $onClass): bool
    {
        $resolved = $this->getName($attribute->name);

        if (!\str_starts_with($resolved, PhpUnitAttributes::NAMESPACE_PREFIX)) {
            return true;
        }

        $short = \substr($resolved, \strlen(PhpUnitAttributes::NAMESPACE_PREFIX));

        if (\in_array($short, PhpUnitAttributes::DROPS, true)) {
            return true;
        }

        if (\in_array($short, PhpUnitAttributes::STRUCTURAL, true)) {
            return !$onClass && $attribute->args === [];
        }

        $arguments = $this->positionalArgs($attribute->args);

        if ($short === PhpUnitAttributes::TEST_WITH) {
            return !$onClass && $this->testWithArgsConvert($attribute);
        }

        if (isset(PhpUnitAttributes::SIZE_GROUPS[$short])) {
            return $attribute->args === [];
        }

        if (isset(PhpUnitAttributes::SKIP_UNLESS_CONDITIONS[$short])) {
            return $arguments !== null && \count($arguments) === 1;
        }

        if (isset(PhpUnitAttributes::RENAMES[$short])) {
            if ($arguments === null) {
                return false;
            }

            $arity = match ($short) {
                'DataProvider', 'Group', 'Ticket' => 1,
                default => 0,
            };

            if ($onClass && ($short === 'DoesNotPerformAssertions' || $short === 'DataProvider')) {
                // NoExpectations and DataSet only target methods.
                return false;
            }

            return \count($arguments) === $arity;
        }

        return false;
    }

    private function testWithArgsConvert(Attribute $attribute): bool
    {
        $count = \count($attribute->args);

        if ($count < 1 || $count > 2) {
            return false;
        }

        foreach ($attribute->args as $index => $argument) {
            if ($argument->unpack) {
                return false;
            }

            $named = $argument->name instanceof Identifier ? $argument->name->toString() : null;

            if ($index === 0 && $named !== null) {
                return false;
            }

            if ($index === 1 && $named !== null && $named !== 'name') {
                return false;
            }
        }

        return true;
    }

    private function dataProvidersConvert(Class_ $class): bool
    {
        foreach ($class->getMethods() as $method) {
            foreach ($method->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ($this->getName($attribute->name) !== PhpUnitAttributes::NAMESPACE_PREFIX . 'DataProvider') {
                        continue;
                    }

                    $argument = $attribute->args[0] ?? null;

                    if ($argument === null || !$argument->value instanceof String_) {
                        return false;
                    }

                    $provider = $class->getMethod($argument->value->value);

                    if (!$provider instanceof ClassMethod || !$provider->isPublic() || !$provider->isStatic()) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param array<int, true> $sanctioned
     */
    private function sanctionParentHookCalls(ClassMethod $method, array &$sanctioned): void
    {
        foreach ($method->stmts ?? [] as $stmt) {
            $call = $stmt instanceof Expression ? $stmt->expr : null;

            if ($call instanceof StaticCall && $this->isParentHookCall($call)) {
                $sanctioned[\spl_object_id($call)] = true;
            }
        }
    }

    private function isParentHookCall(StaticCall $call): bool
    {
        if (!$call->class instanceof Name || $call->class->toLowerString() !== 'parent') {
            return false;
        }

        return $call->name instanceof Identifier
            && \in_array($call->name->toLowerString(), ['setup', 'teardown'], true);
    }

    /**
     * Validates the expectException block. The calls must sit consecutively
     * at the top level. The method body ends with one expression statement,
     * and that statement becomes the toThrow() subject.
     *
     * @param array<int, true> $sanctioned
     */
    private function planThrowRewrite(ClassMethod $method, array &$sanctioned): ThrowRewrite|false|null
    {
        $stmts = $method->stmts ?? [];
        $found = [];

        foreach ($stmts as $index => $stmt) {
            $call = $stmt instanceof Expression ? $stmt->expr : null;

            if (!$call instanceof MethodCall && !$call instanceof StaticCall) {
                continue;
            }

            if (!$this->isSelfReceiver($call)) {
                continue;
            }

            $name = $call->name instanceof Identifier ? $call->name->toLowerString() : null;

            if ($name !== null && \in_array($name, self::EXPECT_EXCEPTION_METHODS, true)) {
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

        if (\max($indices) !== \count($stmts) - 2 || !$stmts[\count($stmts) - 1] instanceof Expression) {
            return false;
        }

        $exception = null;
        $matching = null;

        foreach ($found as [$name, $call]) {
            $arguments = $this->positionalArgs($call->args);

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

        /** @var int<0, max> $first */
        return new ThrowRewrite($first, $exception, $matching);
    }

    /**
     * @param array<int, true> $sanctioned
     */
    private function planNoAssertions(ClassMethod $method, array &$sanctioned): ?bool
    {
        $found = false;

        foreach ($method->stmts ?? [] as $stmt) {
            $call = $stmt instanceof Expression ? $stmt->expr : null;

            if (!$call instanceof MethodCall && !$call instanceof StaticCall) {
                continue;
            }

            if (!$this->isSelfReceiver($call)) {
                continue;
            }

            $name = $call->name instanceof Identifier ? $call->name->toLowerString() : null;

            if ($name !== self::EXPECT_NO_ASSERTIONS) {
                continue;
            }

            if ($call->args !== []) {
                return null;
            }

            $sanctioned[\spl_object_id($call)] = true;
            $found = true;
        }

        return $found;
    }

    /**
     * @param array<string, true> $defined
     * @param array<int, true> $sanctioned
     */
    private function callsConvert(Class_ $class, array $defined, array $sanctioned): bool
    {
        $finder = new NodeFinder();
        $calls = $finder->find($class->stmts, static fn(Node $node): bool => $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
            || $node instanceof StaticCall
            || $node instanceof FuncCall);

        foreach ($calls as $call) {
            if ($call instanceof FuncCall) {
                $name = $call->name instanceof Name ? $this->getName($call->name) : null;

                if ($name !== null && \str_starts_with($name, 'PHPUnit\\')) {
                    return false;
                }

                continue;
            }

            if ($call instanceof NullsafeMethodCall) {
                if ($this->isOwnInstanceReceiver($call->var, $class)) {
                    return false;
                }

                continue;
            }

            if ($call instanceof MethodCall) {
                if (!$this->isOwnInstanceReceiver($call->var, $class)) {
                    continue;
                }

                if (!$this->ownCallConverts($call, $defined, $sanctioned, allowInstanceApi: true)) {
                    return false;
                }

                continue;
            }

            if (!$call instanceof StaticCall) {
                continue;
            }

            $target = $this->staticCallTarget($call, $class);

            if ($target === 'phpunit') {
                return false;
            }

            if ($target === 'parent') {
                if (!isset($sanctioned[\spl_object_id($call)])) {
                    return false;
                }

                continue;
            }

            if ($target === 'own' && !$this->ownCallConverts($call, $defined, $sanctioned, allowInstanceApi: true)) {
                return false;
            }

            if ($target === 'assert' && !$this->ownCallConverts($call, $defined, $sanctioned, allowInstanceApi: false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, true> $defined
     * @param array<int, true> $sanctioned
     */
    private function ownCallConverts(
        MethodCall|StaticCall $call,
        array $defined,
        array $sanctioned,
        bool $allowInstanceApi,
    ): bool {
        $name = $call->name instanceof Identifier ? $call->name->toLowerString() : null;

        if ($name === null) {
            return false;
        }

        if ($allowInstanceApi && isset($defined[$name])) {
            return true;
        }

        $conversion = AssertionMap::lookup($name);

        if ($conversion instanceof AssertionConversion) {
            $arguments = $this->positionalArgs($call->args);

            if ($arguments === null || \count($arguments) < $conversion->arity) {
                return false;
            }

            return \count($arguments) === $conversion->arity || $this->dropAssertionMessages;
        }

        if ($name === self::FAIL) {
            $arguments = $this->positionalArgs($call->args);

            return $arguments !== null && \count($arguments) <= 1;
        }

        if (!$allowInstanceApi) {
            return false;
        }

        if ($name === self::MARK_TEST_SKIPPED) {
            $arguments = $this->positionalArgs($call->args);

            return $arguments !== null && \count($arguments) <= 1;
        }

        if (\in_array($name, self::EXPECT_EXCEPTION_METHODS, true) || $name === self::EXPECT_NO_ASSERTIONS) {
            return isset($sanctioned[\spl_object_id($call)]);
        }

        return false;
    }

    private function isSelfReceiver(MethodCall|StaticCall $call): bool
    {
        if ($call instanceof MethodCall) {
            return $call->var instanceof Variable && $call->var->name === 'this';
        }

        return $call->class instanceof Name
            && \in_array($call->class->toLowerString(), ['self', 'static'], true);
    }

    private function isOwnInstanceReceiver(Expr $receiver, Class_ $class): bool
    {
        if ($receiver instanceof Variable && $receiver->name === 'this') {
            return true;
        }

        return \in_array($this->classFqcn($class), $this->getType($receiver)->getObjectClassNames(), true);
    }

    /**
     * @return 'assert'|'other'|'own'|'parent'|'phpunit'
     */
    private function staticCallTarget(StaticCall $call, Class_ $class): string
    {
        if (!$call->class instanceof Name) {
            if ($this->isOwnInstanceReceiver($call->class, $class)) {
                return 'own';
            }

            return 'other';
        }

        $raw = $call->class->toLowerString();

        if ($raw === 'parent') {
            return 'parent';
        }

        if ($raw === 'self' || $raw === 'static') {
            return 'own';
        }

        $resolved = $this->getName($call->class);

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
     * @param array<Arg|Node\VariadicPlaceholder> $args
     *
     * @return list<Arg>|null Null if an argument is named, unpacked, or a
     *                        first-class callable placeholder
     */
    private function positionalArgs(array $args): ?array
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

    private function hasPhpUnitAttribute(ClassMethod $method, string $short): bool
    {
        return $this->hasAttributeNamed($method->attrGroups, PhpUnitAttributes::NAMESPACE_PREFIX . $short);
    }

    /**
     * @param array<AttributeGroup> $attrGroups
     */
    private function hasAttributeNamed(array $attrGroups, string $name): bool
    {
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->getName($attribute->name) === $name) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<AttributeGroup> $attrGroups
     *
     * @return list<AttributeGroup>
     */
    private function convertAttributeGroups(array $attrGroups, bool $dropOverride): array
    {
        $converted = [];

        foreach ($attrGroups as $group) {
            $kept = [];

            foreach ($group->attrs as $attribute) {
                $replacement = $this->convertAttribute($attribute, $dropOverride);

                if (!$replacement instanceof Attribute) {
                    continue;
                }

                $kept[] = $replacement;
            }

            if ($kept !== []) {
                $converted[] = new AttributeGroup($kept);
            }
        }

        return $converted;
    }

    private function convertAttribute(Attribute $attribute, bool $dropOverride): ?Attribute
    {
        $resolved = $this->getName($attribute->name);

        if ($dropOverride && $resolved === 'Override') {
            return null;
        }

        if (!\str_starts_with($resolved, PhpUnitAttributes::NAMESPACE_PREFIX)) {
            return $attribute;
        }

        $short = \substr($resolved, \strlen(PhpUnitAttributes::NAMESPACE_PREFIX));

        if (\in_array($short, PhpUnitAttributes::DROPS, true) || \in_array($short, PhpUnitAttributes::STRUCTURAL, true)) {
            return null;
        }

        if ($short === PhpUnitAttributes::TEST_WITH) {
            return $this->convertTestWith($attribute);
        }

        if (isset(PhpUnitAttributes::SIZE_GROUPS[$short])) {
            return new Attribute(
                new FullyQualified(Group::class),
                [new Arg(new String_(PhpUnitAttributes::SIZE_GROUPS[$short]))],
            );
        }

        if (isset(PhpUnitAttributes::SKIP_UNLESS_CONDITIONS[$short])) {
            $condition = new ClassConstFetch(
                new FullyQualified(PhpUnitAttributes::SKIP_UNLESS_CONDITIONS[$short]),
                'class',
            );

            return new Attribute(
                new FullyQualified(SkipUnless::class),
                [new Arg($condition), $attribute->args[0]],
            );
        }

        if (isset(PhpUnitAttributes::RENAMES[$short])) {
            return new Attribute(new FullyQualified(PhpUnitAttributes::RENAMES[$short]), $attribute->args);
        }

        return $attribute;
    }

    private function convertTestWith(Attribute $attribute): Attribute
    {
        $arguments = [new Arg($attribute->args[0]->value)];
        $label = $attribute->args[1] ?? null;

        if ($label instanceof Arg) {
            $arguments[] = new Arg($label->value, name: new Identifier('label'));
        }

        return new Attribute(new FullyQualified(DataRow::class), $arguments);
    }

    /**
     * @param class-string $attributeClass
     */
    private function prependAttribute(ClassMethod $method, string $attributeClass): void
    {
        if ($this->hasAttributeNamed($method->attrGroups, $attributeClass)) {
            return;
        }

        \array_unshift(
            $method->attrGroups,
            new AttributeGroup([new Attribute(new FullyQualified($attributeClass))]),
        );
    }

    private function removeParentHookCalls(ClassMethod $method): void
    {
        $method->stmts = \array_values(\array_filter(
            $method->stmts ?? [],
            fn(Node\Stmt $stmt): bool => !(
                $stmt instanceof Expression
                && $stmt->expr instanceof StaticCall
                && $this->isParentHookCall($stmt->expr)
            ),
        ));
    }

    private function removeNoAssertionCalls(ClassMethod $method): void
    {
        $method->stmts = \array_values(\array_filter(
            $method->stmts ?? [],
            function (Node\Stmt $stmt): bool {
                $call = $stmt instanceof Expression ? $stmt->expr : null;

                if (!$call instanceof MethodCall && !$call instanceof StaticCall) {
                    return true;
                }

                if (!$this->isSelfReceiver($call)) {
                    return true;
                }

                $name = $call->name instanceof Identifier ? $call->name->toLowerString() : null;

                return $name !== self::EXPECT_NO_ASSERTIONS;
            },
        ));
    }

    private function applyThrowRewrite(ClassMethod $method, ThrowRewrite $rewrite): void
    {
        $stmts = $method->stmts ?? [];

        if ($stmts === []) {
            return;
        }

        $act = $stmts[\count($stmts) - 1];

        if (!$act instanceof Expression) {
            return;
        }

        $finder = new NodeFinder();
        $usesThis = $finder->findFirst(
            [$act->expr],
            static fn(Node $node): bool => $node instanceof Variable && $node->name === 'this',
        ) instanceof Node;

        $subject = new ArrowFunction([
            'static' => !$usesThis,
            'params' => [],
            'expr' => $act->expr,
        ]);

        $exception = $rewrite->exception ?? new ClassConstFetch(new FullyQualified('Throwable'), 'class');
        $toThrowArgs = [new Arg($exception)];

        if ($rewrite->matching instanceof Expr) {
            $toThrowArgs[] = new Arg($rewrite->matching, name: new Identifier('matching'));
        }

        $expectation = new MethodCall(
            new StaticCall(new FullyQualified(Expect::class), 'that', [new Arg($subject)]),
            'toThrow',
            $toThrowArgs,
        );

        $method->stmts = [
            ...\array_slice($stmts, 0, $rewrite->firstExpectationIndex),
            new Expression($expectation),
        ];
    }

    /**
     * @param array<string, true> $defined
     */
    private function convertCall(Node $node, Class_ $class, array $defined): ?Node
    {
        $allowInstanceApi = true;

        if ($node instanceof StaticCall) {
            $target = $this->staticCallTarget($node, $class);

            if ($target !== 'own' && $target !== 'assert') {
                return null;
            }

            $allowInstanceApi = $target === 'own';
        } elseif ($node instanceof MethodCall) {
            if (!$this->isOwnInstanceReceiver($node->var, $class)) {
                return null;
            }
        } else {
            return null;
        }

        $name = $node->name instanceof Identifier ? $node->name->toLowerString() : null;

        if ($name === null || isset($defined[$name])) {
            return null;
        }

        $arguments = $this->positionalArgs($node->args);

        if ($arguments === null) {
            return null;
        }

        $conversion = AssertionMap::lookup($name);

        if ($conversion instanceof AssertionConversion) {
            return $this->buildExpectation($conversion, $arguments);
        }

        if ($name === self::FAIL) {
            return new StaticCall(
                new FullyQualified(Fail::class),
                'because',
                [$arguments[0] ?? new Arg(new String_('Test failed.'))],
            );
        }

        if ($allowInstanceApi && $name === self::MARK_TEST_SKIPPED) {
            // SkipTest requires a non-empty reason. The PHPUnit argument is optional.
            $reason = $arguments === [] ? [new Arg(new String_('Skipped.'))] : $arguments;

            return new Throw_(new New_(new FullyQualified(SkipTest::class), $reason));
        }

        return null;
    }

    /**
     * @param list<Arg> $arguments
     */
    private function buildExpectation(AssertionConversion $conversion, array $arguments): MethodCall
    {
        $chain = new StaticCall(
            new FullyQualified(Expect::class),
            'that',
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

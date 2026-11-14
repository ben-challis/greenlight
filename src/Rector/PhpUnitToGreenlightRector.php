<?php

declare(strict_types=1);

namespace Greenlight\Rector;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Attribute\NoExpectations;
use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use PhpParser\Comment\Doc;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Attribute;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
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
     * this option, a custom message rejects the class. A manual migration can
     * preserve the message with `because()`.
     */
    public const string DROP_ASSERTION_MESSAGES = 'drop_assertion_messages';

    private const string TEST_CASE_CLASS = 'PHPUnit\Framework\TestCase';

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

    private bool $dropAssertionMessages = false;

    private ?PhpUnitAttributeConverter $attributeConverter = null;


    private ?OwnCallClassifier $callClassifier = null;


    /**
     * @param mixed[] $configuration
     *
     * @throws \InvalidArgumentException if a key is unknown or `drop_assertion_messages` is not a boolean
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
                            \Greenlight\Expect\Expect::value(Price::fromString('9.99')->times(2)->format())->toBe('19.98');
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

        $attributes = $this->attributeConverter();
        $classGroups = $attributes->convertGroups($class->attrGroups, onClass: true, dropOverride: false);

        if ($classGroups === null || $this->hasPhpUnitAnnotation($class)) {
            return null;
        }

        $providers = $attributes->referencedDataProviders($class);
        $attributeGroups = [\spl_object_id($class) => $classGroups];
        $hooks = [];
        $hookOrder = ['before' => [], 'after' => []];
        $tests = [];
        $noExpectations = [];
        $throwRewrites = [];
        $sanctioned = [];

        foreach ($class->getMethods() as $method) {
            $name = $method->name->toLowerString();

            if (\in_array($name, self::UNSUPPORTED_TEMPLATE_METHODS, true) || $this->hasPhpUnitAnnotation($method)) {
                return null;
            }

            $kind = $this->classify($method);

            if ($kind === null) {
                return null;
            }

            $isHook = $kind === 'before' || $kind === 'after';
            $groups = $attributes->convertGroups($method->attrGroups, onClass: false, dropOverride: $isHook);

            if ($groups === null) {
                return null;
            }

            if ($isHook) {
                $hooks[$name] = $kind;
                $hookOrder[$kind][] = $name;
                $this->sanctionParentHookCalls($method, $sanctioned);
                $attributeGroups[\spl_object_id($method)] = $attributes->withPrependedAttribute(
                    $groups,
                    $kind === 'before' ? Before::class : After::class,
                );

                continue;
            }

            if ($attributes->hasAttributeNamed($method->attrGroups, 'Override')) {
                // #[\Override] on anything but a hook points at the removed parent.
                return null;
            }

            if ($kind === 'helper' && $this->isStaticTestNamed($method) && !isset($providers[$name])) {
                // PHPUnit runs a public static test method. A Greenlight
                // test must not be static, so the method would stop running.
                return null;
            }

            $attributeGroups[\spl_object_id($method)] = $groups;

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
                $groups = $attributes->withPrependedAttribute($groups, NoExpectations::class);
            }

            $attributeGroups[\spl_object_id($method)] = $attributes->withPrependedAttribute($groups, Test::class);
        }

        if (!$this->hookOrderConverts($hookOrder['before'], 'before')
            || !$this->hookOrderConverts($hookOrder['after'], 'after')
        ) {
            return null;
        }

        if (!$attributes->dataProvidersConvert($class)) {
            return null;
        }

        $calls = $this->planCalls($class, $sanctioned);

        return $calls === null
            ? null
            : new ClassPlan($attributeGroups, $hooks, $tests, $noExpectations, $throwRewrites, $calls);
    }

    private function convert(Class_ $class, ClassPlan $plan): void
    {
        $class->extends = null;
        $class->attrGroups = $plan->attributeGroups[\spl_object_id($class)];

        foreach ($class->getMethods() as $method) {
            $name = $method->name->toLowerString();
            $method->attrGroups = $plan->attributeGroups[\spl_object_id($method)];

            if (isset($plan->hooks[$name])) {
                $method->flags &= ~(Modifiers::PROTECTED | Modifiers::PRIVATE);
                $method->flags |= Modifiers::PUBLIC;
                $this->removeParentHookCalls($method);
            }

            if (\in_array($name, $plan->tests, true)) {
                $rewrite = $plan->throwRewrites[$name] ?? null;

                if ($rewrite instanceof ThrowRewrite) {
                    $this->applyThrowRewrite($method, $rewrite);
                }

                if (\in_array($name, $plan->noExpectations, true)) {
                    $this->removeNoAssertionCalls($method);
                }
            }
        }

        $this->traverseNodesWithCallable(
            $class->stmts,
            static fn(Node $node): ?Node => $plan->calls[\spl_object_id($node)] ?? null,
        );
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

    private function hasPhpUnitAnnotation(Class_|ClassMethod $node): bool
    {
        $docComment = $node->getDocComment();

        return $docComment instanceof Doc && \preg_match(PhpUnitAttributes::ANNOTATION_PATTERN, $docComment->getText()) === 1;
    }

    /**
     * Compares the hook run order of PHPUnit and Greenlight. PHPUnit runs
     * `#[Before]` methods in reverse declaration order and then `setUp()`.
     * It runs `tearDown()` and then `#[After]` methods in declaration order.
     * Greenlight runs before-hooks in declaration order and after-hooks in
     * reverse declaration order.
     *
     * @param list<string> $declared lowercase hook names in declaration order
     * @param 'after'|'before' $kind
     */
    private function hookOrderConverts(array $declared, string $kind): bool
    {
        $template = $kind === 'before' ? 'setup' : 'teardown';
        $attributed = \array_values(\array_filter($declared, static fn(string $name): bool => $name !== $template));
        $templates = \in_array($template, $declared, true) ? [$template] : [];

        if ($kind === 'before') {
            return [...\array_reverse($attributed), ...$templates] === $declared;
        }

        return [...$templates, ...$attributed] === \array_reverse($declared);
    }

    private function isStaticTestNamed(ClassMethod $method): bool
    {
        return $method->isPublic() && $method->isStatic() && \str_starts_with($method->name->toLowerString(), 'test');
    }

    /**
     * @return 'after'|'before'|'helper'|'test'|null
     */
    private function classify(ClassMethod $method): ?string
    {
        $name = $method->name->toLowerString();
        $isBefore = $name === 'setup' || $this->attributeConverter()->hasPhpUnitAttribute($method, 'Before');
        $isAfter = $name === 'teardown' || $this->attributeConverter()->hasPhpUnitAttribute($method, 'After');
        $hasTestAttribute = $this->attributeConverter()->hasPhpUnitAttribute($method, 'Test');
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

        if (\max($indices) !== \count($stmts) - 2 || !$stmts[\count($stmts) - 1] instanceof Expression) {
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

            if (!OwnCallClassifier::isSelfReceiver($call)) {
                continue;
            }

            $name = $call->name instanceof Identifier ? $call->name->toLowerString() : null;

            if ($name !== OwnCallClassifier::EXPECT_NO_ASSERTIONS) {
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

                if (!OwnCallClassifier::isSelfReceiver($call)) {
                    return true;
                }

                $name = $call->name instanceof Identifier ? $call->name->toLowerString() : null;

                return $name !== OwnCallClassifier::EXPECT_NO_ASSERTIONS;
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
            new StaticCall(new FullyQualified(Expect::class), 'calling', [new Arg($subject)]),
            'toThrow',
            $toThrowArgs,
        );

        $method->stmts = [
            ...\array_slice($stmts, 0, $rewrite->firstExpectationIndex),
            new Expression($expectation),
        ];
    }

    /**
     * @param array<int, true> $sanctioned
     *
     * @return array<int, Expr>|null
     */
    private function planCalls(Class_ $class, array $sanctioned): ?array
    {
        $defined = $this->definedMethods($class);
        $finder = new NodeFinder();
        $calls = $finder->find($class->stmts, static fn(Node $node): bool => $node instanceof MethodCall
            || $node instanceof NullsafeMethodCall
            || $node instanceof StaticCall
            || $node instanceof FuncCall);
        $replacements = [];

        foreach ($calls as $call) {
            $replacement = $this->callClassifier()->classify(
                $call,
                $class,
                $defined,
                $sanctioned,
                $this->dropAssertionMessages,
            );

            if ($replacement === false) {
                return null;
            }

            if ($replacement instanceof Expr) {
                $replacements[\spl_object_id($call)] = $replacement;
            }
        }

        return $replacements;
    }

    private function callClassifier(): OwnCallClassifier
    {
        return $this->callClassifier ??= new OwnCallClassifier($this->nodeNameResolver, $this->nodeTypeResolver);
    }

    private function attributeConverter(): PhpUnitAttributeConverter
    {
        return $this->attributeConverter ??= new PhpUnitAttributeConverter($this->nodeNameResolver, $this->nodeTypeResolver);
    }
}

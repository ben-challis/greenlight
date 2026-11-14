<?php

declare(strict_types=1);

namespace Greenlight\Rector;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Expression;

/**
 * Classifies the methods of a PHPUnit test class as hooks, tests, or
 * helpers.
 *
 * `classify()` returns the kind of each method, or null when a member has
 * no faithful Greenlight equivalent. It rejects PHPUnit doc-comment
 * annotations, a hook order that Greenlight does not keep, and a public
 * static test method.
 *
 * `hookStatements()`, `planNoAssertions()`, and `withoutNoAssertionCalls()`
 * plan the statement changes for hooks and tests.
 *
 * @internal
 */
final readonly class PhpUnitMemberClassifier
{
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

    public function __construct(private PhpUnitAttributeConverter $attributes) {}

    /**
     * @param array<string, true> $providers lowercase data provider names
     *
     * @return array<int, 'after'|'before'|'helper'|'test'>|null method kinds
     *   keyed by object ID, or null if a member rejects the class
     */
    public function classify(Class_ $class, array $providers): ?array
    {
        if ($this->hasPhpUnitAnnotation($class)) {
            return null;
        }

        $kinds = [];
        $hookOrder = ['before' => [], 'after' => []];

        foreach ($class->getMethods() as $method) {
            $name = $method->name->toLowerString();

            if (\in_array($name, self::UNSUPPORTED_TEMPLATE_METHODS, true) || $this->hasPhpUnitAnnotation($method)) {
                return null;
            }

            $kind = $this->classifyMethod($method);

            if ($kind === null) {
                return null;
            }

            $kinds[\spl_object_id($method)] = $kind;

            if ($kind === 'before' || $kind === 'after') {
                $hookOrder[$kind][] = $name;

                continue;
            }

            if ($this->attributes->hasAttributeNamed($method->attrGroups, 'Override')) {
                // #[\Override] on anything but a hook points at the removed parent.
                return null;
            }

            if ($kind === 'helper' && $this->isStaticTestNamed($method) && !isset($providers[$name])) {
                // PHPUnit runs a public static test method. A Greenlight
                // test must not be static, so the method would stop running.
                return null;
            }
        }

        if (!$this->hookOrderConverts($hookOrder['before'], 'before')
            || !$this->hookOrderConverts($hookOrder['after'], 'after')
        ) {
            return null;
        }

        return $kinds;
    }

    /**
     * Returns the hook statements without the top-level `parent::setUp()`
     * and `parent::tearDown()` calls.
     *
     * @param array<int, true> $sanctioned object IDs of the removed calls
     *
     * @return list<Stmt>
     */
    public function hookStatements(ClassMethod $method, array &$sanctioned): array
    {
        $kept = [];

        foreach ($method->stmts ?? [] as $stmt) {
            $call = $stmt instanceof Expression ? $stmt->expr : null;

            if ($call instanceof StaticCall && $this->isParentHookCall($call)) {
                $sanctioned[\spl_object_id($call)] = true;

                continue;
            }

            $kept[] = $stmt;
        }

        return $kept;
    }

    /**
     * @param array<int, true> $sanctioned object IDs of the removed calls
     *
     * @return bool|null null if an `expectNotToPerformAssertions()` call has
     *   arguments
     */
    public function planNoAssertions(ClassMethod $method, array &$sanctioned): ?bool
    {
        $found = false;

        foreach ($method->stmts ?? [] as $stmt) {
            $call = $this->noAssertionCall($stmt);

            if ($call === null) {
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
     * @param array<Stmt> $stmts
     *
     * @return list<Stmt>
     */
    public function withoutNoAssertionCalls(array $stmts): array
    {
        return \array_values(\array_filter($stmts, fn(Stmt $stmt): bool => $this->noAssertionCall($stmt) === null));
    }

    /**
     * @return 'after'|'before'|'helper'|'test'|null
     */
    private function classifyMethod(ClassMethod $method): ?string
    {
        $name = $method->name->toLowerString();
        $isBefore = $name === 'setup' || $this->attributes->hasPhpUnitAttribute($method, 'Before');
        $isAfter = $name === 'teardown' || $this->attributes->hasPhpUnitAttribute($method, 'After');
        $hasTestAttribute = $this->attributes->hasPhpUnitAttribute($method, 'Test');
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

    private function hasPhpUnitAnnotation(Class_|ClassMethod $node): bool
    {
        $docComment = $node->getDocComment();

        return $docComment !== null && \preg_match(PhpUnitAttributes::ANNOTATION_PATTERN, $docComment->getText()) === 1;
    }

    private function isStaticTestNamed(ClassMethod $method): bool
    {
        return $method->isPublic() && $method->isStatic() && \str_starts_with($method->name->toLowerString(), 'test');
    }

    private function isParentHookCall(StaticCall $call): bool
    {
        if (!$call->class instanceof Name || $call->class->toLowerString() !== 'parent') {
            return false;
        }

        return $call->name instanceof Identifier
            && \in_array($call->name->toLowerString(), ['setup', 'teardown'], true);
    }

    private function noAssertionCall(Stmt $stmt): MethodCall|StaticCall|null
    {
        $call = $stmt instanceof Expression ? $stmt->expr : null;

        if (!$call instanceof MethodCall && !$call instanceof StaticCall) {
            return null;
        }

        if (!OwnCallClassifier::isSelfReceiver($call)) {
            return null;
        }

        $name = $call->name instanceof Identifier ? $call->name->toLowerString() : null;

        return $name === OwnCallClassifier::EXPECT_NO_ASSERTIONS ? $call : null;
    }
}

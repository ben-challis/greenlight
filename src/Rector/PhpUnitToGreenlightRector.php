<?php

declare(strict_types=1);

namespace Greenlight\Rector;

use Greenlight\Attribute\After;
use Greenlight\Attribute\Before;
use Greenlight\Attribute\NoExpectations;
use Greenlight\Attribute\Test;
use PhpParser\Modifiers;
use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
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

    private bool $dropAssertionMessages = false;

    private ?PhpUnitAttributeConverter $attributeConverter = null;

    private ?PhpUnitMemberClassifier $memberClassifier = null;

    private ?OwnCallClassifier $callClassifier = null;

    private ?ThrowBlockPlanner $throwBlockPlanner = null;

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
        $members = $this->memberClassifier();
        $kinds = $members->classify($class, $attributes->referencedDataProviders($class));
        $classGroups = $attributes->convertGroups($class->attrGroups, onClass: true, dropOverride: false);

        if ($kinds === null || $classGroups === null || !$attributes->dataProvidersConvert($class)) {
            return null;
        }

        $attributeGroups = [\spl_object_id($class) => $classGroups];
        $publicMethods = [];
        $statements = [];
        $sanctioned = [];

        foreach ($class->getMethods() as $method) {
            $id = \spl_object_id($method);
            $kind = $kinds[$id];
            $isHook = $kind === 'before' || $kind === 'after';
            $groups = $attributes->convertGroups($method->attrGroups, onClass: false, dropOverride: $isHook);

            if ($groups === null) {
                return null;
            }

            if ($isHook) {
                $publicMethods[$id] = true;
                $statements[$id] = $members->hookStatements($method, $sanctioned);
                $groups = $attributes->withPrependedAttribute($groups, $kind === 'before' ? Before::class : After::class);
            }

            if ($kind === 'test') {
                $rewritten = $this->throwBlockPlanner()->plan($method, $sanctioned);
                $noAssertions = $members->planNoAssertions($method, $sanctioned);

                if ($rewritten === false || $noAssertions === null) {
                    return null;
                }

                if (\is_array($rewritten)) {
                    $statements[$id] = $rewritten;
                }

                if ($noAssertions) {
                    $statements[$id] = $members->withoutNoAssertionCalls($statements[$id] ?? $method->stmts ?? []);
                    $groups = $attributes->withPrependedAttribute($groups, NoExpectations::class);
                }

                $groups = $attributes->withPrependedAttribute($groups, Test::class);
            }

            $attributeGroups[$id] = $groups;
        }

        $calls = $this->planCalls($class, $sanctioned);

        return $calls === null ? null : new ClassPlan($attributeGroups, $publicMethods, $statements, $calls);
    }

    /**
     * @param array<int, true> $sanctioned
     *
     * @return array<int, Node\Expr>|null
     */
    private function planCalls(Class_ $class, array $sanctioned): ?array
    {
        $defined = [];

        foreach ($class->getMethods() as $method) {
            $defined[$method->name->toLowerString()] = true;
        }

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

            if ($replacement instanceof Node\Expr) {
                $replacements[\spl_object_id($call)] = $replacement;
            }
        }

        return $replacements;
    }

    private function convert(Class_ $class, ClassPlan $plan): void
    {
        $class->extends = null;
        $class->attrGroups = $plan->attributeGroups[\spl_object_id($class)];

        foreach ($class->getMethods() as $method) {
            $id = \spl_object_id($method);
            $method->attrGroups = $plan->attributeGroups[$id];

            if (isset($plan->publicMethods[$id])) {
                $method->flags &= ~(Modifiers::PROTECTED | Modifiers::PRIVATE);
                $method->flags |= Modifiers::PUBLIC;
            }

            if (isset($plan->statements[$id])) {
                $method->stmts = $plan->statements[$id];
            }
        }

        $this->traverseNodesWithCallable(
            $class->stmts,
            static fn(Node $node): ?Node => $plan->calls[\spl_object_id($node)] ?? null,
        );
    }

    private function attributeConverter(): PhpUnitAttributeConverter
    {
        return $this->attributeConverter ??= new PhpUnitAttributeConverter($this->nodeNameResolver, $this->nodeTypeResolver);
    }

    private function memberClassifier(): PhpUnitMemberClassifier
    {
        return $this->memberClassifier ??= new PhpUnitMemberClassifier($this->attributeConverter());
    }

    private function callClassifier(): OwnCallClassifier
    {
        return $this->callClassifier ??= new OwnCallClassifier($this->nodeNameResolver, $this->nodeTypeResolver);
    }

    private function throwBlockPlanner(): ThrowBlockPlanner
    {
        return $this->throwBlockPlanner ??= new ThrowBlockPlanner();
    }
}

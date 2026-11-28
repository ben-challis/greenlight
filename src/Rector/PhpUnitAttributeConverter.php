<?php

declare(strict_types=1);

namespace Greenlight\Rector;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Group;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\NoExpectations;
use Greenlight\Attribute\SkipUnless;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Attribute;
use PhpParser\Node\AttributeGroup;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name\FullyQualified;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use Rector\NodeNameResolver\NodeNameResolver;
use Rector\NodeTypeResolver\NodeTypeResolver;

/**
 * Converts the PHPUnit attributes of one declaration to Greenlight
 * attributes.
 *
 * `convertGroups()` validates and converts a declaration in one call. It
 * returns the replacement attribute groups, or null when an attribute has no
 * faithful Greenlight equivalent.
 *
 * `dataProvidersConvert()` checks each `#[DataProvider]` target and its
 * literal rows. `referencedDataProviders()` lists the provider names.
 *
 * @internal
 */
final readonly class PhpUnitAttributeConverter
{
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

    public function __construct(
        private NodeNameResolver $names,
        private NodeTypeResolver $types,
    ) {}

    /**
     * @param array<AttributeGroup> $attrGroups
     *
     * @return list<AttributeGroup>|null null if an attribute rejects the class
     */
    public function convertGroups(array $attrGroups, bool $onClass, bool $dropOverride): ?array
    {
        $singleAttributes = [];
        $converted = [];

        foreach ($attrGroups as $group) {
            $kept = [];

            foreach ($group->attrs as $attribute) {
                if (!$this->attributeConverts($attribute, $onClass)) {
                    return null;
                }

                $singleAttribute = $this->singleAttributeOutput($attribute);

                if ($singleAttribute !== null) {
                    if (isset($singleAttributes[$singleAttribute])) {
                        return null;
                    }

                    $singleAttributes[$singleAttribute] = true;
                }

                $replacement = $this->convertAttribute($attribute, $dropOverride);

                if ($replacement instanceof Attribute) {
                    $kept[] = $replacement;
                }
            }

            if ($kept !== []) {
                $converted[] = new AttributeGroup($kept);
            }
        }

        return $converted;
    }

    /**
     * @param list<AttributeGroup> $attrGroups
     * @param class-string $attributeClass
     *
     * @return list<AttributeGroup>
     */
    public function withPrependedAttribute(array $attrGroups, string $attributeClass): array
    {
        if ($this->hasAttributeNamed($attrGroups, $attributeClass)) {
            return $attrGroups;
        }

        return [new AttributeGroup([new Attribute(new FullyQualified($attributeClass))]), ...$attrGroups];
    }

    /**
     * @param array<AttributeGroup> $attrGroups
     */
    public function hasAttributeNamed(array $attrGroups, string $name): bool
    {
        foreach ($attrGroups as $group) {
            foreach ($group->attrs as $attribute) {
                if ($this->name($attribute->name) === $name) {
                    return true;
                }
            }
        }

        return false;
    }

    public function hasPhpUnitAttribute(ClassMethod $method, string $short): bool
    {
        return $this->hasAttributeNamed($method->attrGroups, PhpUnitAttributes::NAMESPACE_PREFIX . $short);
    }

    /**
     * @return array<string, true> lowercase names from string `#[DataProvider]` arguments
     */
    public function referencedDataProviders(Class_ $class): array
    {
        $providers = [];

        foreach ($this->dataProviderArguments($class) as $argument) {
            if ($argument instanceof Arg && $argument->value instanceof String_) {
                $providers[\strtolower($argument->value->value)] = true;
            }
        }

        return $providers;
    }

    public function dataProvidersConvert(Class_ $class): bool
    {
        foreach ($this->dataProviderArguments($class) as $argument) {
            if ($argument === null || !$argument->value instanceof String_) {
                return false;
            }

            $provider = $class->getMethod($argument->value->value);

            if (!$provider instanceof ClassMethod || !$provider->isPublic() || !$provider->isStatic()) {
                return false;
            }

            if (!$this->providerRowsConvert($provider)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<Arg|null> the first argument of each `#[DataProvider]`
     */
    private function dataProviderArguments(Class_ $class): array
    {
        $arguments = [];

        foreach ($class->getMethods() as $method) {
            foreach ($method->attrGroups as $group) {
                foreach ($group->attrs as $attribute) {
                    if ($this->name($attribute->name) === PhpUnitAttributes::NAMESPACE_PREFIX . 'DataProvider') {
                        $arguments[] = $attribute->args[0] ?? null;
                    }
                }
            }
        }

        return $arguments;
    }

    /**
     * Returns the Greenlight attribute when the conversion can emit it only
     * once on one declaration.
     *
     * @return class-string|null
     */
    private function singleAttributeOutput(Attribute $attribute): ?string
    {
        $resolved = $this->name($attribute->name);

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
        $resolved = $this->name($attribute->name);

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

        $arguments = OwnCallClassifier::positionalArgs($attribute->args);

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

        return $this->rowKeysConvert($attribute->args[0]->value);
    }

    /**
     * Checks each literal row that a data provider returns or yields. A
     * provider that builds rows another way keeps its rows unchecked.
     */
    private function providerRowsConvert(ClassMethod $provider): bool
    {
        $finder = new NodeFinder();
        $stmts = $provider->stmts ?? [];
        $rows = \array_map(
            static fn(Expr\Yield_ $yield): ?Expr => $yield->value,
            $finder->findInstanceOf($stmts, Expr\Yield_::class),
        );
        $rowSets = [
            ...\array_map(
                static fn(Node\Stmt\Return_ $return): ?Expr => $return->expr,
                $finder->findInstanceOf($stmts, Node\Stmt\Return_::class),
            ),
            ...\array_map(
                static fn(Expr\YieldFrom $yieldFrom): Expr => $yieldFrom->expr,
                $finder->findInstanceOf($stmts, Expr\YieldFrom::class),
            ),
        ];

        foreach ($rowSets as $rowSet) {
            if (!$rowSet instanceof Expr\Array_) {
                continue;
            }

            foreach ($rowSet->items as $item) {
                $rows[] = $item?->value;
            }
        }

        return \array_all(
            $rows,
            fn(?Expr $row): bool => !$row instanceof Expr\Array_ || $this->rowKeysConvert($row),
        );
    }

    /**
     * PHPUnit 11 spreads a data row, so a string key becomes a named
     * argument. Greenlight passes the row values in order and ignores the
     * keys. Only integer keys give the same arguments in both.
     */
    private function rowKeysConvert(Expr $row): bool
    {
        if (!$row instanceof Expr\Array_) {
            $type = $this->types->getType($row);

            return $type->isArray()->yes() && $type->getIterableKeyType()->isInteger()->yes();
        }

        foreach ($row->items as $item) {
            if (!$item instanceof ArrayItem || $item->unpack) {
                return false;
            }

            if ($item->key instanceof Expr && !$item->key instanceof Node\Scalar\Int_) {
                return false;
            }
        }

        return true;
    }

    private function convertAttribute(Attribute $attribute, bool $dropOverride): ?Attribute
    {
        $resolved = $this->name($attribute->name);

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

    private function name(Node $node): string
    {
        return $this->names->getName($node) ?? '';
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Renders reflected parameter, property, and return types back to PHP source
 * for generated proxies. Handles named, nullable, union, and intersection
 * types, including intersections nested in unions. self and parent resolve
 * against the declaring class so the rendered code is position independent;
 * static stays literal because it is only valid, and correct, in the proxy.
 *
 * @internal
 */
final class TypeRenderer
{
    private function __construct() {}

    /**
     * @param \ReflectionClass<object> $context the class declaring the member
     */
    public static function render(\ReflectionType $type, \ReflectionClass $context): string
    {
        if ($type instanceof \ReflectionNamedType) {
            $rendered = self::renderNamed($type, $context);

            if ($type->allowsNull() && !\in_array($type->getName(), ['null', 'mixed'], true)) {
                return '?' . $rendered;
            }

            return $rendered;
        }

        if ($type instanceof \ReflectionUnionType) {
            $members = [];

            foreach ($type->getTypes() as $member) {
                $members[] = $member instanceof \ReflectionIntersectionType
                    ? '(' . self::renderIntersection($member, $context) . ')'
                    : self::renderNamed(self::expectNamed($member), $context);
            }

            if ($type->allowsNull() && !\in_array('null', $members, true)) {
                $members[] = 'null';
            }

            return \implode('|', $members);
        }

        if ($type instanceof \ReflectionIntersectionType) {
            return self::renderIntersection($type, $context);
        }

        throw new DoublesError(\sprintf('Unsupported reflection type %s.', $type::class));
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private static function renderIntersection(\ReflectionIntersectionType $type, \ReflectionClass $context): string
    {
        return \implode('&', \array_map(
            static fn(\ReflectionType $member): string => self::renderNamed(self::expectNamed($member), $context),
            $type->getTypes(),
        ));
    }

    /**
     * @param \ReflectionClass<object> $context
     */
    private static function renderNamed(\ReflectionNamedType $type, \ReflectionClass $context): string
    {
        $name = $type->getName();

        if ($type->isBuiltin() || $name === 'static') {
            return $name;
        }

        if ($name === 'self') {
            return '\\' . $context->name;
        }

        if ($name === 'parent') {
            $parent = $context->getParentClass();

            if ($parent === false) {
                throw new DoublesError(\sprintf('%s uses the parent type but has no parent class.', $context->name));
            }

            return '\\' . $parent->name;
        }

        return '\\' . $name;
    }

    private static function expectNamed(\ReflectionType $type): \ReflectionNamedType
    {
        if (!$type instanceof \ReflectionNamedType) {
            throw new DoublesError(\sprintf('Unsupported nested reflection type %s.', $type::class));
        }

        return $type;
    }
}

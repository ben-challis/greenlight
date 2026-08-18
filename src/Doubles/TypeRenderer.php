<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Converts reflected parameter, property, and return types to PHP source for
 * generated proxy classes.
 *
 * render() processes named, nullable, union, and intersection types. It also
 * processes intersections inside unions.
 *
 * self and parent resolve against the class that declares the member. Thus,
 * their result does not depend on the code position. static remains literal
 * because it is valid only in the proxy class.
 *
 * @internal
 */
final class TypeRenderer
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param \ReflectionClass<object> $context Class that declares the member.
     * @throws DoublesError
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

        throw DoublesError::unsupportedReflectionType($type::class);
    }

    /**
     * @param \ReflectionClass<object> $context
     * @throws DoublesError
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
     * @throws DoublesError
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
                throw DoublesError::parentTypeWithoutParent($context->name);
            }

            return '\\' . $parent->name;
        }

        return '\\' . $name;
    }

    /**
     * @throws DoublesError
     */
    private static function expectNamed(\ReflectionType $type): \ReflectionNamedType
    {
        if (!$type instanceof \ReflectionNamedType) {
            throw DoublesError::unsupportedNestedReflectionType($type::class);
        }

        return $type;
    }
}

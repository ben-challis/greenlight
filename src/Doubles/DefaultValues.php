<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Derives the value an unconfigured doubled method returns, from its declared
 * return type. Nullable and untyped returns yield null, scalars yield their
 * zero value, self and static yield the double itself, instantiable classes
 * yield a constructor-less instance. Interfaces, intersections, enums, and
 * never have no derivable default; those calls need a configured return.
 *
 * @internal
 */
final class DefaultValues
{
    private function __construct() {}

    public static function forMethod(object $double, string $method): mixed
    {
        $declared = new \ReflectionMethod($double, $method);
        $type = $declared->getReturnType() ?? $declared->getTentativeReturnType();

        if ($type === null) {
            return null;
        }

        $default = self::forType($type, $double);

        if ($default instanceof Underivable) {
            throw new DoublesError(\sprintf(
                'No default return value can be derived for %s::%s(): %s. Configure the call with andReturns().',
                $declared->getDeclaringClass()->name,
                $method,
                $default->reason,
            ));
        }

        return $default;
    }

    private static function forType(\ReflectionType $type, object $double): mixed
    {
        if ($type->allowsNull()) {
            return null;
        }

        if ($type instanceof \ReflectionNamedType) {
            return self::forNamedType($type, $double);
        }

        if ($type instanceof \ReflectionUnionType) {
            foreach ($type->getTypes() as $member) {
                $default = self::forType($member, $double);

                if (!$default instanceof Underivable) {
                    return $default;
                }
            }

            return new Underivable('no member of the union type has a derivable default');
        }

        return new Underivable('intersection types have no derivable default');
    }

    private static function forNamedType(\ReflectionNamedType $type, object $double): mixed
    {
        $name = $type->getName();

        if ($type->isBuiltin()) {
            return match ($name) {
                'int' => 0,
                'float' => 0.0,
                'string' => '',
                'bool', 'false' => false,
                'true' => true,
                'array', 'iterable' => [],
                'callable' => static fn(): mixed => null,
                'void', 'null', 'mixed' => null,
                'never' => new Underivable('the method is declared to never return'),
                'object' => new \stdClass(),
                default => new Underivable(\sprintf('the type %s has no derivable default', $name)),
            };
        }

        if (in_array($name, ['self', 'static', 'parent'], true)) {
            return $double;
        }

        if ($double instanceof $name) {
            return $double;
        }

        if (\class_exists($name)) {
            $reflection = new \ReflectionClass($name);

            if (!$reflection->isAbstract() && !$reflection->isEnum()) {
                try {
                    return $reflection->newInstanceWithoutConstructor();
                } catch (\ReflectionException) {
                    return new Underivable(\sprintf('%s cannot be instantiated without its constructor', $name));
                }
            }
        }

        return new Underivable(\sprintf('the type %s has no derivable default', $name));
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

/**
 * Writes a global constant name for a generated proxy. The proxy uses a
 * different namespace from the declaration. Thus, each name must be fully
 * qualified.
 *
 * PHP resolves an unqualified constant in the declaration namespace first and
 * then in the global namespace. The resolver applies the same fallback when
 * it writes the proxy.
 *
 * @internal
 */
final readonly class ConstantReference
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param string $name The constant name relative to the global namespace.
     * @param bool $globalFallback Set to true when the declaration can use the global constant with the same short name.
     *
     * @throws InvalidDoubleUsage
     */
    public static function render(string $name, bool $globalFallback, \ReflectionParameter $parameter): string
    {
        $name = \ltrim($name, '\\');

        if (\defined($name)) {
            return '\\' . $name;
        }

        $separator = \strrpos($name, '\\');

        if ($globalFallback && $separator !== false) {
            $short = \substr($name, $separator + 1);

            if (\defined($short)) {
                return '\\' . $short;
            }
        }

        throw InvalidDoubleUsage::defaultConstantUnresolvable($parameter->name);
    }
}

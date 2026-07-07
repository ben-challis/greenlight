<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/**
 * Deep equality used by Expectation::toEqual().
 *
 * The full semantics are documented on the Expectation class docblock; this
 * class only implements them.
 *
 * @internal
 */
final class Equality
{
    private function __construct() {}

    public static function equals(mixed $a, mixed $b): bool
    {
        return self::compare($a, $b, []);
    }

    /**
     * @param list<non-empty-string> $comparing object pairs already on the
     *   comparison stack, so cyclic structures terminate
     */
    private static function compare(mixed $a, mixed $b, array $comparing): bool
    {
        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            if (\is_int($a) && \is_int($b)) {
                return $a === $b;
            }

            return (float) $a === (float) $b;
        }

        if (\is_array($a) && \is_array($b)) {
            if (\count($a) !== \count($b)) {
                return false;
            }
            return array_all($a, fn($value, $key) => \array_key_exists($key, $b) && self::compare($value, $b[$key], $comparing));
        }

        if ($a instanceof \UnitEnum || $b instanceof \UnitEnum) {
            return $a === $b;
        }

        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return $a->format('U.u') === $b->format('U.u');
        }

        if ($a instanceof \Closure || $b instanceof \Closure) {
            return $a === $b;
        }

        if (\is_object($a) && \is_object($b)) {
            if ($a::class !== $b::class) {
                return false;
            }

            if ($a === $b) {
                return true;
            }

            $pair = \spl_object_id($a) . ':' . \spl_object_id($b);

            if (\in_array($pair, $comparing, true)) {
                return true;
            }

            $comparing[] = $pair;

            $aProperties = \get_mangled_object_vars($a);
            $bProperties = \get_mangled_object_vars($b);

            if (\count($aProperties) !== \count($bProperties)) {
                return false;
            }
            return array_all($aProperties, fn($value, $name) => \array_key_exists($name, $bProperties) && self::compare($value, $bProperties[$name], $comparing));
        }

        return $a === $b;
    }
}

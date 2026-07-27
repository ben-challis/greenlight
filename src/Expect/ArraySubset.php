<?php

declare(strict_types=1);

namespace Greenlight\Expect;

/** @internal */
final class ArraySubset
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * Returns null when each subset key exists in the subject with an equal
     * value. Nested arrays also match as subsets. Otherwise, it describes the
     * first difference, for example, "missing key 'user.address.city'".
     *
     * @param array<array-key, mixed> $subset
     * @param array<array-key, mixed> $subject
     */
    public static function firstDifference(array $subset, array $subject, string $path = ''): ?string
    {
        foreach ($subset as $key => $expected) {
            $keyPath = $path === '' ? (string) $key : $path . '.' . $key;

            if (!\array_key_exists($key, $subject)) {
                return \sprintf("missing key '%s'", $keyPath);
            }

            $actual = $subject[$key];

            if (\is_array($expected) && \is_array($actual)) {
                $difference = self::firstDifference($expected, $actual, $keyPath);

                if ($difference !== null) {
                    return $difference;
                }

                continue;
            }

            if (!Equality::equals($expected, $actual)) {
                return \sprintf("mismatched value at key '%s'", $keyPath);
            }
        }

        return null;
    }
}

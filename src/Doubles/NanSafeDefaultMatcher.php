<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

use Greenlight\Expect\ValueRenderer;

/**
 * Matches a default value that is not identical to itself, such as `NAN` or
 * an array that contains `NAN`. Two `NAN` values match. All other values use
 * strict comparison.
 *
 * @internal
 */
final readonly class NanSafeDefaultMatcher implements ArgumentMatcher
{
    public function __construct(private mixed $value) {}

    public function matches(mixed $value): bool
    {
        return self::same($this->value, $value);
    }

    public static function containsNan(mixed $value): bool
    {
        if (\is_array($value)) {
            return \array_any($value, self::containsNan(...));
        }

        return \is_float($value) && \is_nan($value);
    }

    public function describe(): string
    {
        return new ValueRenderer()->render($this->value);
    }

    private static function same(mixed $expected, mixed $actual): bool
    {
        if (\is_float($expected) && \is_float($actual) && \is_nan($expected) && \is_nan($actual)) {
            return true;
        }

        if (!\is_array($expected) || !\is_array($actual)) {
            return $expected === $actual;
        }

        if (\array_keys($expected) !== \array_keys($actual)) {
            return false;
        }

        return \array_all($expected, static fn(mixed $item, int|string $key): bool => self::same($item, $actual[$key]));
    }
}

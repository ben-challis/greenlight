<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Wire\Utf8;

/**
 * Produces bounded, single-line failure values. The renderer converts the
 * output to valid UTF-8 because a JSON message contains the failure details.
 *
 * @internal
 */
final class ValueRenderer
{
    private const int MAX_DEPTH = 3;

    private const int MAX_ITEMS = 10;

    private const int MAX_STRING_CHARS = 120;

    public function render(mixed $value): string
    {
        return Utf8::scrub($this->renderValue($value, 0));
    }

    private function renderValue(mixed $value, int $depth): string
    {
        if ($value === null) {
            return 'null';
        }

        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (\is_int($value)) {
            return (string) $value;
        }

        if (\is_float($value)) {
            return $this->renderFloat($value);
        }

        if (\is_string($value)) {
            return $this->renderString($value);
        }

        if (\is_array($value)) {
            return $this->renderArray($value, $depth);
        }

        if ($value instanceof \UnitEnum) {
            return $value::class . '::' . $value->name;
        }

        if ($value instanceof \DateTimeInterface) {
            return $value::class . '(' . $value->format('Y-m-d\TH:i:s.uP') . ')';
        }

        if (\is_object($value) && !$value instanceof \Closure) {
            return $this->renderObject($value, $depth);
        }

        return \get_debug_type($value) . ' (unrendered)';
    }

    private function renderFloat(float $value): string
    {
        if (\is_nan($value)) {
            return 'NAN';
        }

        if (\is_infinite($value)) {
            return $value > 0.0 ? 'INF' : '-INF';
        }

        $rendered = (string) $value;

        if (\str_contains($rendered, '.') || \str_contains($rendered, 'E') || \str_contains($rendered, 'e')) {
            return $rendered;
        }

        return $rendered . '.0';
    }

    private function renderString(string $value): string
    {
        $value = Utf8::scrub($value);
        $printable = '';
        $printableCharacters = 0;
        $offset = 0;
        $bytes = \strlen($value);

        while ($offset < $bytes) {
            if (\preg_match('/./us', $value, $matches, offset: $offset) !== 1) {
                break;
            }

            $character = $matches[0];
            $escaped = $this->escapeCharacter($character);
            $escapedCharacters = $this->codePointLength($escaped);

            if ($printableCharacters + $escapedCharacters > self::MAX_STRING_CHARS) {
                break;
            }

            $printable .= $escaped;
            $printableCharacters += $escapedCharacters;
            $offset += \strlen($character);
        }

        if ($offset === $bytes) {
            return "'" . $printable . "'";
        }

        return \sprintf(
            "'%s...' (truncated from %d characters)",
            $printable,
            $this->codePointLength($value),
        );
    }

    private function codePointLength(string $value): int
    {
        $length = \preg_match_all('/./us', $value);

        return $length === false ? \strlen($value) : $length;
    }

    private function escapeCharacter(string $character): string
    {
        $escaped = \strtr($character, [
            '\\' => '\\\\',
            "'" => "\\'",
            "\n" => '\n',
            "\r" => '\r',
            "\t" => '\t',
            "\0" => '\0',
        ]);

        if ($escaped !== $character || \preg_match('/^\p{Cc}$/u', $character) !== 1) {
            return $escaped;
        }

        $codePoint = \strlen($character) === 1
            ? \ord($character)
            : ((\ord($character[0]) & 0x1f) << 6) | (\ord($character[1]) & 0x3f);

        return \sprintf('\\u{%04X}', $codePoint);
    }

    /**
     * @param array<array-key, mixed> $value
     */
    private function renderArray(array $value, int $depth): string
    {
        if ($value === []) {
            return '[]';
        }

        if ($depth >= self::MAX_DEPTH) {
            return '[...]';
        }

        $isList = \array_is_list($value);
        $parts = [];
        $rendered = 0;

        foreach ($value as $key => $item) {
            if ($rendered === self::MAX_ITEMS) {
                $parts[] = \sprintf('... +%d more', \count($value) - self::MAX_ITEMS);

                break;
            }

            $parts[] = $isList
                ? $this->renderValue($item, $depth + 1)
                : $this->renderValue($key, $depth + 1) . ' => ' . $this->renderValue($item, $depth + 1);
            ++$rendered;
        }

        return '[' . \implode(', ', $parts) . ']';
    }

    private function renderObject(object $value, int $depth): string
    {
        if ($depth >= self::MAX_DEPTH) {
            return $value::class . ' {...}';
        }

        $parts = [];
        $rendered = 0;

        $reflection = new \ReflectionObject($value);

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if ($rendered === self::MAX_ITEMS) {
                $parts[] = '...';

                break;
            }

            $renderedValue = match (true) {
                $property->isVirtual() => '(virtual)',
                !$property->isInitialized($value) => '(uninitialized)',
                default => $this->renderValue($property->getRawValue($value), $depth + 1),
            };
            $parts[] = $property->getName() . ': ' . $renderedValue;
            ++$rendered;
        }

        return $value::class . ' {' . \implode(', ', $parts) . '}';
    }
}

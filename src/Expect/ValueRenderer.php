<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Core\Wire\Utf8;

/**
 * Renders arbitrary values as readable, bounded, single-line strings for
 * failure messages. Covered: scalars, null, arrays (depth-limited), enums,
 * DateTimeInterface, and plain objects via reflection (class name plus a
 * depth-limited property map). Everything else falls back to get_debug_type()
 * plus an "(unrendered)" marker. Output is always valid UTF-8: every rendered
 * string is scrubbed before it leaves this class, because failure details
 * cross a JSON wire.
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
        $printable = \strtr($value, [
            '\\' => '\\\\',
            "\n" => '\n',
            "\r" => '\r',
            "\t" => '\t',
            "\0" => '\0',
        ]);

        if (\strlen($printable) <= self::MAX_STRING_CHARS) {
            return "'" . $printable . "'";
        }

        return \sprintf(
            "'%s...' (truncated from %d characters)",
            \substr($printable, 0, self::MAX_STRING_CHARS),
            \strlen($value),
        );
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

            $parts[] = $property->getName() . ': ' . ($property->isInitialized($value)
                ? $this->renderValue($property->getValue($value), $depth + 1)
                : '(uninitialized)');
            ++$rendered;
        }

        return $value::class . ' {' . \implode(', ', $parts) . '}';
    }
}

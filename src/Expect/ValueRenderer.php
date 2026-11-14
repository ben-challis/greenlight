<?php

declare(strict_types=1);

namespace Greenlight\Expect;

use Greenlight\Internal\Text\Utf8;

/**
 * Produces bounded, single-line failure values. The renderer converts the
 * output to valid UTF-8 because a JSON message contains the failure details.
 *
 * `render()` applies depth, item, string, and total byte limits.
 * `renderDifference()` also finds the first difference between two values
 * when these limits hide it.
 *
 * @internal
 */
final class ValueRenderer
{
    private const int MAX_DEPTH = 3;

    private const int MAX_ITEMS = 10;

    private const int MAX_STRING_CHARS = 120;

    private const int MAX_RENDERED_BYTES = 2048;

    /**
     * A UTF-8 character has at most four bytes. A prefix of this length
     * contains all characters that a truncated string shows.
     */
    private const int STRING_SCAN_BYTES = self::MAX_STRING_CHARS * 4 + 4;

    /** Bytes that a string window shows before the first difference. */
    private const int WINDOW_LEAD_BYTES = 30;

    private const int WINDOW_CHARS = 80;

    private const int MAX_DIFFERENCE_DEPTH = 32;

    private const string MISSING = '(missing)';

    private bool $truncated = false;

    private static ?object $missing = null;

    public function render(mixed $value): string
    {
        $this->truncated = false;
        $rendered = Utf8::scrub($this->renderValue($value, 0));

        if (\strlen($rendered) <= self::MAX_RENDERED_BYTES) {
            return $rendered;
        }

        $this->truncated = true;

        return Utf8::headBytes($rendered, self::MAX_RENDERED_BYTES - 3) . '...';
    }

    /**
     * Renders two values that are not equal. If the rendered values are
     * identical or truncated, the result also describes the first difference.
     * For arrays and objects, the description contains the path. For strings,
     * it contains the character offset and a window around it.
     *
     * @param \Closure(mixed, mixed): bool $equal
     *
     * @return array{actual: string, expected: string, difference: string|null}
     */
    public function renderDifference(mixed $actual, mixed $expected, \Closure $equal): array
    {
        $renderedActual = $this->render($actual);
        $hidden = $this->truncated;
        $renderedExpected = $this->render($expected);
        $hidden = $hidden || $this->truncated || $renderedActual === $renderedExpected;

        return [
            'actual' => $renderedActual,
            'expected' => $renderedExpected,
            'difference' => $hidden ? $this->describeDifference($actual, $expected, $equal) : null,
        ];
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

    /**
     * Uses the shortest representation that converts back to the same float.
     * Thus, 0.1 + 0.2 does not render as 0.3.
     */
    private function renderFloat(float $value): string
    {
        if (\is_nan($value)) {
            return 'NAN';
        }

        if (\is_infinite($value)) {
            return $value > 0.0 ? 'INF' : '-INF';
        }

        $precision = 1;

        while ($precision < 17 && (float) \sprintf('%.' . $precision . 'G', $value) !== $value) {
            ++$precision;
        }

        $rendered = \sprintf('%.' . $precision . 'G', $value);

        if (\str_contains($rendered, '.')) {
            return $rendered;
        }

        if (\str_contains($rendered, 'E')) {
            return \str_replace('E', '.0E', $rendered);
        }

        return $rendered . '.0';
    }

    private function renderString(string $value): string
    {
        $value = Utf8::scrub($value);
        [$printable, $consumed] = $this->escapePrefix($value);

        if ($consumed === \strlen($value)) {
            return "'" . $printable . "'";
        }

        $this->truncated = true;

        return \sprintf(
            "'%s...' (truncated from %d characters)",
            $printable,
            $this->codePointLength($value),
        );
    }

    /**
     * Escapes characters from the start of a valid UTF-8 string until the
     * printable text reaches the character limit.
     *
     * @return array{string, int} The printable text and the consumed bytes
     */
    private function escapePrefix(string $value, int $maxCharacters = self::MAX_STRING_CHARS): array
    {
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

            if ($printableCharacters + $escapedCharacters > $maxCharacters) {
                break;
            }

            $printable .= $escaped;
            $printableCharacters += $escapedCharacters;
            $offset += \strlen($character);
        }

        return [$printable, $offset];
    }

    /**
     * Counts code points without a scan of each character. A valid UTF-8
     * string has one non-continuation byte for each code point.
     */
    private function characterCount(string $value): int
    {
        if (\preg_match('//u', $value) !== 1) {
            return $this->codePointLength(Utf8::scrub($value));
        }

        return \strlen($value) - (int) \preg_match_all('/[\x80-\xBF]/', $value);
    }

    private function codePointLength(string $value): int
    {
        $length = \preg_match_all('/./us', $value);

        return $length === false ? \strlen($value) : $length;
    }

    /**
     * Escapes control characters and all whitespace or format characters
     * except the ASCII space. Thus, a no-break space or a zero-width space
     * does not look like a space or like nothing.
     */
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

        if ($escaped !== $character
            || $character === ' '
            || \preg_match('/^[\p{Cc}\p{Z}\p{Cf}]$/u', $character) !== 1
        ) {
            return $escaped;
        }

        return \sprintf('\\u{%04X}', $this->codePoint($character));
    }

    private function codePoint(string $character): int
    {
        $bytes = \array_map(\ord(...), \str_split($character));

        return match (\count($bytes)) {
            1 => $bytes[0],
            2 => (($bytes[0] & 0x1F) << 6) | ($bytes[1] & 0x3F),
            3 => (($bytes[0] & 0x0F) << 12) | (($bytes[1] & 0x3F) << 6) | ($bytes[2] & 0x3F),
            default => (($bytes[0] & 0x07) << 18) | (($bytes[1] & 0x3F) << 12)
                | (($bytes[2] & 0x3F) << 6) | ($bytes[3] & 0x3F),
        };
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
            $this->truncated = true;

            return '[...]';
        }

        $isList = \array_is_list($value);
        $parts = [];
        $rendered = 0;
        $bytes = 0;

        foreach ($value as $key => $item) {
            if ($rendered === self::MAX_ITEMS || $bytes > self::MAX_RENDERED_BYTES) {
                $this->truncated = true;
                $parts[] = \sprintf('... +%d more', \count($value) - $rendered);

                break;
            }

            $part = $isList
                ? $this->renderValue($item, $depth + 1)
                : $this->renderValue($key, $depth + 1) . ' => ' . $this->renderValue($item, $depth + 1);
            $parts[] = $part;
            $bytes += \strlen($part) + 2;
            ++$rendered;
        }

        return '[' . \implode(', ', $parts) . ']';
    }

    private function renderObject(object $value, int $depth): string
    {
        if ($depth >= self::MAX_DEPTH) {
            $this->truncated = true;

            return $value::class . ' {...}';
        }

        $parts = [];
        $rendered = 0;
        $bytes = 0;

        $reflection = new \ReflectionObject($value);

        foreach ($reflection->getProperties() as $property) {
            if ($property->isStatic()) {
                continue;
            }

            if ($rendered === self::MAX_ITEMS || $bytes > self::MAX_RENDERED_BYTES) {
                $this->truncated = true;
                $parts[] = '...';

                break;
            }

            $renderedValue = match (true) {
                $property->isVirtual() => '(virtual)',
                !$property->isInitialized($value) => '(uninitialized)',
                default => $this->renderValue($property->getRawValue($value), $depth + 1),
            };
            $part = $property->getName() . ': ' . $renderedValue;
            $parts[] = $part;
            $bytes += \strlen($part) + 2;
            ++$rendered;
        }

        return $value::class . ' {' . \implode(', ', $parts) . '}';
    }

    /**
     * @param \Closure(mixed, mixed): bool $equal
     */
    private function describeDifference(mixed $actual, mixed $expected, \Closure $equal): ?string
    {
        try {
            $difference = $this->firstDifference($actual, $expected, $equal, '', 0);
        } catch (\Throwable) {
            // The diagnostic must not replace the expectation failure.
            return null;
        }

        if ($difference === null) {
            return null;
        }

        [$path, $left, $right] = $difference;

        if (\is_string($left) && \is_string($right)) {
            [$offset, $byte] = $this->stringDifference($left, $right);

            return \sprintf(
                'first difference at %soffset %d: actual %s, expected %s',
                $path === '' ? '' : $path . ', ',
                $offset,
                $this->renderWindow($left, $byte),
                $this->renderWindow($right, $byte),
            );
        }

        if ($path === '') {
            return null;
        }

        return \sprintf(
            'first difference at %s: actual %s, expected %s',
            $path,
            $this->renderMember($left),
            $this->renderMember($right),
        );
    }

    /**
     * @param \Closure(mixed, mixed): bool $equal
     *
     * @return array{string, mixed, mixed}|null
     */
    private function firstDifference(mixed $actual, mixed $expected, \Closure $equal, string $path, int $depth): ?array
    {
        if ($equal($actual, $expected)) {
            return null;
        }

        if ($depth === self::MAX_DIFFERENCE_DEPTH) {
            return [$path, $actual, $expected];
        }

        if (\is_array($actual) && \is_array($expected)) {
            $member = $this->firstMemberDifference($actual, $expected, $equal, $path, $depth, $this->arrayKeyPath(...));

            if ($member !== null) {
                return $member;
            }

            if (\array_keys($actual) !== \array_keys($expected)) {
                return [$path . ' (key order)', \array_keys($actual), \array_keys($expected)];
            }

            return [$path, $actual, $expected];
        }

        if (\is_object($actual) && \is_object($expected) && $actual::class === $expected::class
            && !$actual instanceof \Closure
        ) {
            return $this->firstMemberDifference(
                \get_mangled_object_vars($actual),
                \get_mangled_object_vars($expected),
                $equal,
                $path,
                $depth,
                $this->propertyPath(...),
            ) ?? [$path, $actual, $expected];
        }

        return [$path, $actual, $expected];
    }

    /**
     * @param array<array-key, mixed> $actual
     * @param array<array-key, mixed> $expected
     * @param \Closure(mixed, mixed): bool $equal
     * @param \Closure(int|string): string $memberPath
     *
     * @return array{string, mixed, mixed}|null
     */
    private function firstMemberDifference(
        array $actual,
        array $expected,
        \Closure $equal,
        string $path,
        int $depth,
        \Closure $memberPath,
    ): ?array {
        foreach ($actual as $key => $value) {
            if (!\array_key_exists($key, $expected)) {
                return [$path . $memberPath($key), $value, $this->missing()];
            }

            $difference = $this->firstDifference($value, $expected[$key], $equal, $path . $memberPath($key), $depth + 1);

            if ($difference !== null) {
                return $difference;
            }
        }

        foreach ($expected as $key => $value) {
            if (!\array_key_exists($key, $actual)) {
                return [$path . $memberPath($key), $this->missing(), $value];
            }
        }

        return null;
    }

    private function arrayKeyPath(int|string $key): string
    {
        return '[' . (\is_int($key) ? $key : $this->renderString($key)) . ']';
    }

    private function propertyPath(int|string $key): string
    {
        $name = (string) $key;
        $separator = \strrpos($name, "\0");

        return '->' . ($separator === false ? $name : \substr($name, $separator + 1));
    }

    private function renderMember(mixed $value): string
    {
        return $value === $this->missing() ? self::MISSING : $this->render($value);
    }

    private function missing(): object
    {
        return self::$missing ??= new \stdClass();
    }

    /**
     * @return array{int, int} The character offset and the byte offset of the
     *   first difference
     */
    private function stringDifference(string $actual, string $expected): array
    {
        $length = \min(\strlen($actual), \strlen($expected));
        $byte = \strspn(\substr($actual, 0, $length) ^ \substr($expected, 0, $length), "\0");

        while ($byte > 0 && $byte < \strlen($actual) && (\ord($actual[$byte]) & 0xC0) === 0x80) {
            --$byte;
        }

        return [$this->characterCount(\substr($actual, 0, $byte)), $byte];
    }

    /**
     * Renders a bounded part of a string that starts shortly before a byte
     * offset.
     */
    private function renderWindow(string $value, int $byte): string
    {
        $start = \max(0, $byte - self::WINDOW_LEAD_BYTES);

        while ($start > 0 && $start < \strlen($value) && (\ord($value[$start]) & 0xC0) === 0x80) {
            --$start;
        }

        $slice = \substr($value, $start, self::STRING_SCAN_BYTES);
        [$printable, $consumed] = $this->escapePrefix(Utf8::scrub($slice), self::WINDOW_CHARS);
        $complete = \strlen($slice) < self::STRING_SCAN_BYTES && $consumed >= \strlen(Utf8::scrub($slice));

        return ($start > 0 ? "'..." : "'") . $printable . ($complete ? "'" : "...'");
    }
}

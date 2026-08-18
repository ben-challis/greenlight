<?php

declare(strict_types=1);

namespace Greenlight\Tools\Fuzz;

/**
 * Makes one deterministic, JSON-compatible mutation to a valid payload.
 *
 * @internal
 */
final class StructuredInput
{
    private function __construct() {}

    /**
     * @param non-empty-list<array<string, mixed>> $templates
     *
     * @return array<string, mixed>
     */
    public static function mutate(array $templates, string $input): array
    {
        $selector = $input[0] ?? "\0";
        $index = \ctype_digit($selector)
            ? (int) $selector % \count($templates)
            : \ord($selector) % \count($templates);
        $template = $templates[$index];

        if (\strlen($input) < 3) {
            return $template;
        }

        $paths = self::paths($template);

        if ($paths === []) {
            return $template;
        }

        $path = $paths[\ord($input[1]) % \count($paths)];

        $mutated = self::replace(
            $template,
            $path,
            \ord($input[2]) % 16,
            \substr($input, 3),
        );

        return self::map($mutated) ?? [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function map(mixed $value): ?array
    {
        if (!\is_array($value) || ($value !== [] && \array_is_list($value))) {
            return null;
        }

        $map = [];

        foreach ($value as $key => $item) {
            if (!\is_string($key)) {
                return null;
            }

            $map[$key] = $item;
        }

        return $map;
    }

    /**
     * @param array<mixed> $value
     *
     * @param list<int|string> $prefix
     *
     * @return list<list<int|string>>
     */
    private static function paths(array $value, array $prefix = []): array
    {
        $paths = [];

        foreach ($value as $key => $child) {
            $path = [...$prefix, $key];
            $paths[] = $path;

            if (\is_array($child)) {
                $paths = [...$paths, ...self::paths($child, $path)];
            }
        }

        return $paths;
    }

    /**
     * @param array<mixed> $value
     * @param list<int|string> $path
     *
     * @return array<mixed>
     */
    private static function replace(array $value, array $path, int $operation, string $bytes): array
    {
        if ($path === []) {
            return $value;
        }

        $key = \array_shift($path);

        if ($path !== []) {
            if (\is_array($value[$key] ?? null)) {
                $value[$key] = self::replace($value[$key], $path, $operation, $bytes);
            }

            return $value;
        }

        if ($operation === 0) {
            unset($value[$key]);

            return $value;
        }

        $current = $value[$key] ?? null;
        $text = \bin2hex($bytes);
        $value[$key] = match ($operation) {
            1 => null,
            2 => false,
            3 => true,
            4 => 0,
            5 => -1,
            6 => \PHP_INT_MAX,
            7 => 0.5,
            8 => '',
            9 => $text,
            10 => [],
            11 => [$text],
            12 => [null, 1, $text],
            13 => ['fuzz' => $text],
            14 => \is_array($current) ? [...$current, 'fuzz' => $text] : ['value' => $current],
            default => [0 => $current],
        };

        return $value;
    }
}

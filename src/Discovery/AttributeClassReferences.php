<?php

declare(strict_types=1);

namespace Greenlight\Discovery;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Finds the classes that attribute arguments in a PHP file name in constant
 * expressions, for example `Cases::GROUP` in `#[Group(Cases::GROUP)]`.
 *
 * Reflection gives only the evaluated argument values. Thus, in() reads the
 * tokens of each `#[...]` group and resolves each `Name::` prefix with the
 * namespace and `use` imports of the file. It ignores `self`, `static`, and
 * `parent`. The discovery cache tracks the files of these classes. A changed
 * constant value then makes the cache entry stale.
 *
 * @internal
 */
final class AttributeClassReferences
{
    #[CoverageIgnore]
    private function __construct() {}

    /**
     * Returns an empty list if the file cannot be read.
     *
     * @return list<non-empty-string> fully qualified names without a leading backslash
     */
    public static function in(string $file): array
    {
        $code = ErrorTrap::run(static fn() => \file_get_contents($file));

        if (!\is_string($code) || !\str_contains($code, '#[')) {
            return [];
        }

        $tokens = \array_values(\array_filter(
            \PhpToken::tokenize($code),
            static fn(\PhpToken $token): bool => !$token->isIgnorable(),
        ));
        $count = \count($tokens);
        $namespace = '';
        $imports = [];
        $braceDepth = 0;
        $attributeDepth = 0;
        $names = [];

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if ($attributeDepth > 0) {
                if ($token->text === '[') {
                    ++$attributeDepth;
                } elseif ($token->text === ']') {
                    --$attributeDepth;
                } elseif (
                    $token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED, \T_NAME_RELATIVE])
                    && ($tokens[$i + 1] ?? null)?->is(\T_DOUBLE_COLON) === true
                ) {
                    $name = self::resolve($token, $namespace, $imports);

                    if ($name !== null) {
                        $names[$name] = $name;
                    }
                }

                continue;
            }

            if ($token->is(\T_ATTRIBUTE)) {
                $attributeDepth = 1;
            } elseif ($token->is([\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES]) || $token->text === '{') {
                ++$braceDepth;
            } elseif ($token->text === '}') {
                --$braceDepth;
            } elseif ($token->is(\T_NAMESPACE) && ($tokens[$i + 1] ?? null)?->is([\T_STRING, \T_NAME_QUALIFIED]) === true) {
                $namespace = $tokens[$i + 1]->text;
                $imports = [];
            } elseif ($token->is(\T_USE) && $braceDepth === 0 && ($tokens[$i + 1] ?? null)?->text !== '(') {
                $i = self::readImports($tokens, $i + 1, $imports);
            }
        }

        return \array_values($names);
    }

    /**
     * Reads one top-level `use` statement. Function and constant imports do
     * not name classes, so this method skips them.
     *
     * @param list<\PhpToken> $tokens
     * @param array<string, string> $imports lowercase alias to fully qualified name
     *
     * @param-out array<string, string> $imports
     *
     * @return int the index of the statement terminator
     */
    private static function readImports(array $tokens, int $index, array &$imports): int
    {
        $count = \count($tokens);

        if ($index < $count && $tokens[$index]->is([\T_FUNCTION, \T_CONST])) {
            while ($index < $count && $tokens[$index]->text !== ';') {
                ++$index;
            }

            return $index;
        }

        $prefix = '';
        $name = null;
        $alias = null;
        $expectAlias = false;

        for (; $index < $count; ++$index) {
            $token = $tokens[$index];

            if ($token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                if ($expectAlias) {
                    $alias = $token->text;
                    $expectAlias = false;
                } else {
                    $name = \ltrim($token->text, '\\');
                }

                continue;
            }

            if ($token->is(\T_AS)) {
                $expectAlias = true;

                continue;
            }

            if ($token->text === '{') {
                $prefix = $name === null ? '' : \rtrim($name, '\\') . '\\';
                $name = null;

                continue;
            }

            if (\in_array($token->text, [',', '}', ';'], true)) {
                if ($name !== null) {
                    $full = $prefix . $name;
                    $short = $alias ?? (\strrchr($full, '\\') === false ? $full : \substr(\strrchr($full, '\\'), 1));
                    $imports[\strtolower($short)] = $full;
                }

                $name = null;
                $alias = null;

                if ($token->text === ';') {
                    return $index;
                }
            }
        }

        return $index;
    }

    /**
     * @param array<string, string> $imports
     *
     * @return non-empty-string|null
     */
    private static function resolve(\PhpToken $token, string $namespace, array $imports): ?string
    {
        $text = $token->text;

        if ($token->is(\T_NAME_FULLY_QUALIFIED)) {
            $resolved = \substr($text, 1);
        } elseif ($token->is(\T_NAME_RELATIVE)) {
            $resolved = self::prefixed($namespace, \substr($text, \strlen('namespace\\')));
        } else {
            $lower = \strtolower($text);

            if (\in_array($lower, ['self', 'static', 'parent'], true)) {
                return null;
            }

            $separator = \strpos($text, '\\');
            $first = $separator === false ? $lower : \strtolower(\substr($text, 0, $separator));

            if (isset($imports[$first])) {
                $resolved = $separator === false ? $imports[$first] : $imports[$first] . \substr($text, $separator);
            } else {
                $resolved = self::prefixed($namespace, $text);
            }
        }

        return $resolved === '' ? null : $resolved;
    }

    private static function prefixed(string $namespace, string $name): string
    {
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }
}

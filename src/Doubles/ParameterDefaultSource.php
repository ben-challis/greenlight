<?php

declare(strict_types=1);

namespace Greenlight\Doubles;

use Greenlight\Internal\Php\ErrorTrap;

/**
 * Reads a parameter default and its original namespace without a call to its constructors.
 * Source files must remain available while Greenlight generates the proxy class.
 *
 * @internal
 */
final class ParameterDefaultSource
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @return array{tokens: list<\PhpToken>, namespace: string, imports: array<string, string>, constants: array<string, string>, file: string, method: string, trait: string}|null
     */
    public static function read(\ReflectionParameter $parameter): ?array
    {
        $method = $parameter->getDeclaringFunction();

        if (!$method instanceof \ReflectionMethod) {
            return null;
        }

        $file = $method->getFileName();

        if ($file === false || !\is_file($file)) {
            return null;
        }

        $source = ErrorTrap::run(static fn() => \file_get_contents($file));

        if ($source === false) {
            return null;
        }

        $tokens = \array_values(\PhpToken::tokenize($source));
        $origins = self::origins($method);
        $namespace = '';
        $imports = [];
        $constants = [];
        $namespaceDepth = 0;
        $depth = 0;
        $classes = [];
        $classStarts = [];
        $matches = [];
        $ambiguous = [];
        $count = \count($tokens);

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if ($token->is(\T_NAMESPACE) && $depth === 0) {
                $namespace = '';
                $imports = [];
                $constants = [];

                while (isset($tokens[++$i]) && !$tokens[$i]->is([';', '{'])) {
                    if (!$tokens[$i]->isIgnorable()) {
                        $namespace .= $tokens[$i]->text;
                    }
                }

                if (isset($tokens[$i]) && $tokens[$i]->is('{')) {
                    ++$depth;
                }

                $namespaceDepth = $depth;

                continue;
            }

            if ($token->is(\T_USE) && $depth === $namespaceDepth) {
                $next = self::significantIndex($tokens, $i + 1);

                if ($next !== null && $tokens[$next]->is('(')) {
                    continue;
                }

                $end = $i + 1;

                while (isset($tokens[$end]) && !$tokens[$end]->is(';')) {
                    ++$end;
                }

                self::readImports(\array_slice($tokens, $i + 1, $end - $i - 1), $imports, $constants);
                $i = $end;

                continue;
            }

            if ($token->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM])) {
                $previous = self::significantIndex($tokens, $i - 1, -1);

                if ($previous !== null && $tokens[$previous]->is(\T_DOUBLE_COLON)) {
                    continue;
                }

                $nameIndex = self::significantIndex($tokens, $i + 1);
                $name = $nameIndex !== null && $tokens[$nameIndex]->is(\T_STRING)
                    ? \ltrim($namespace . '\\' . $tokens[$nameIndex]->text, '\\')
                    : '';

                if ($name === '' && $method->getDeclaringClass()->isAnonymous() && $token->line === $method->getDeclaringClass()->getStartLine()) {
                    $name = $method->getDeclaringClass()->name;
                }

                $body = self::classBody($tokens, $i + 1);

                if ($body !== null) {
                    $classStarts[$body] = ['name' => $name, 'trait' => $token->is(\T_TRAIT) ? $name : ''];
                }
            }

            if ($token->is(['{', \T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                ++$depth;

                if (isset($classStarts[$i])) {
                    $classes[] = $classStarts[$i] + ['depth' => $depth];
                }

                continue;
            }

            if ($token->is('}')) {
                $class = $classes === [] ? null : $classes[\array_key_last($classes)];

                if ($class !== null && $class['depth'] === $depth) {
                    \array_pop($classes);
                }

                --$depth;

                if ($depth < $namespaceDepth) {
                    $namespace = '';
                    $imports = [];
                    $constants = [];
                    $namespaceDepth = 0;
                }

                continue;
            }

            if (!$token->is(\T_FUNCTION) || $token->line !== $method->getStartLine() || $classes === []) {
                continue;
            }

            $class = $classes[\array_key_last($classes)];
            $nameIndex = self::significantIndex($tokens, $i + 1);

            if ($nameIndex !== null && $tokens[$nameIndex]->text === '&') {
                $nameIndex = self::significantIndex($tokens, $nameIndex + 1);
            }

            if ($class['depth'] !== $depth || $nameIndex === null || !isset($origins[$class['name']]) || $tokens[$nameIndex]->text !== $origins[$class['name']]) {
                continue;
            }

            $expression = self::expression($tokens, $nameIndex + 1, $parameter->getPosition());

            if ($expression !== null) {
                if (isset($matches[$class['name']])) {
                    $ambiguous[$class['name']] = true;
                }

                $matches[$class['name']] = [
                    'tokens' => $expression,
                    'namespace' => $namespace,
                    'imports' => $imports,
                    'constants' => $constants,
                    'file' => $file,
                    'method' => $tokens[$nameIndex]->text,
                    'trait' => $class['trait'],
                ];
            }
        }

        $owner = $method->getDeclaringClass()->name;

        if (isset($matches[$owner])) {
            return isset($ambiguous[$owner]) ? null : $matches[$owner];
        }

        return \count($matches) === 1 && $ambiguous === [] ? \array_values($matches)[0] : null;
    }

    /**
     * @return array<string, string>
     */
    private static function origins(\ReflectionMethod $method): array
    {
        $class = $method->getDeclaringClass();
        $origins = [$class->name => $method->name];

        foreach ($class->getTraitAliases() as $alias => $original) {
            if (\strcasecmp($alias, $method->name) === 0) {
                [$trait, $name] = \explode('::', $original, 2);
                $origins += self::origins(new \ReflectionMethod($trait, $name));
            }
        }

        foreach ($class->getTraits() as $trait) {
            if (!$trait->hasMethod($method->name)) {
                continue;
            }

            $candidate = $trait->getMethod($method->name);

            if ($candidate->getFileName() === $method->getFileName() && $candidate->getStartLine() === $method->getStartLine() && $candidate->getEndLine() === $method->getEndLine()) {
                $origins += self::origins($candidate);
            }
        }

        return $origins;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function significantIndex(array $tokens, int $index, int $step = 1): ?int
    {
        for ($i = $index; isset($tokens[$i]); $i += $step) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private static function classBody(array $tokens, int $start): ?int
    {
        $depth = 0;

        for ($i = $start; isset($tokens[$i]); ++$i) {
            $token = $tokens[$i];

            if ($token->is('{') && $depth === 0) {
                return $i;
            }

            if ($token->is(['(', '[', '{', \T_ATTRIBUTE])) {
                ++$depth;
            } elseif ($token->is([')', ']', '}'])) {
                --$depth;
            }
        }

        return null;
    }

    /**
     * @param list<\PhpToken> $tokens
     * @return list<\PhpToken>|null
     */
    private static function expression(array $tokens, int $start, int $position): ?array
    {
        $open = self::significantIndex($tokens, $start);

        if ($open === null || !$tokens[$open]->is('(')) {
            return null;
        }

        $depth = 0;
        $parameter = 0;
        $default = null;

        for ($i = $open + 1; isset($tokens[$i]); ++$i) {
            $token = $tokens[$i];

            if ($depth === 0 && $token->is([',', ')'])) {
                if ($parameter === $position) {
                    if ($default === null) {
                        return null;
                    }

                    $first = self::significantIndex($tokens, $default);
                    $last = self::significantIndex($tokens, $i - 1, -1);

                    return $first !== null && $last !== null && $first <= $last
                        ? \array_slice($tokens, $first, $last - $first + 1)
                        : null;
                }

                if ($token->is(')')) {
                    return null;
                }

                ++$parameter;
                $default = null;

                continue;
            }

            if ($depth === 0 && $token->is('=')) {
                $default = $i + 1;
            } elseif ($token->is(['(', '[', '{', \T_ATTRIBUTE])) {
                ++$depth;
            } elseif ($token->is([')', ']', '}'])) {
                --$depth;
            }
        }

        return null;
    }

    /**
     * @param list<\PhpToken> $tokens
     * @param array<string, string> $imports
     * @param array<string, string> $constants
     */
    private static function readImports(array $tokens, array &$imports, array &$constants): void
    {
        $tokens = \array_values(\array_filter($tokens, static fn(\PhpToken $token): bool => !$token->isIgnorable()));
        $kind = \T_CLASS;

        if (isset($tokens[0]) && $tokens[0]->is([\T_CONST, \T_FUNCTION])) {
            $kind = $tokens[0]->id;
            \array_shift($tokens);
        }

        $prefix = '';
        $group = null;

        foreach ($tokens as $i => $token) {
            if ($token->is('{')) {
                $group = $i;

                break;
            }
        }

        if ($group !== null) {
            $prefix = \implode('', \array_map(static fn(\PhpToken $token): string => $token->text, \array_slice($tokens, 0, $group)));
            $tokens = \array_slice($tokens, $group + 1, -1);
        }

        $entry = [];

        foreach ($tokens as $token) {
            if ($token->is(',')) {
                self::readImport($entry, $prefix, $kind, $imports, $constants);
                $entry = [];
            } else {
                $entry[] = $token;
            }
        }

        self::readImport($entry, $prefix, $kind, $imports, $constants);
    }

    /**
     * @param list<\PhpToken> $tokens
     * @param array<string, string> $imports
     * @param array<string, string> $constants
     */
    private static function readImport(array $tokens, string $prefix, int $kind, array &$imports, array &$constants): void
    {
        if ($tokens === []) {
            return;
        }

        if ($tokens[0]->is([\T_CONST, \T_FUNCTION])) {
            $kind = $tokens[0]->id;
            \array_shift($tokens);
        }

        if ($kind === \T_FUNCTION) {
            return;
        }

        $name = $prefix;
        $alias = '';
        $afterAlias = false;

        foreach ($tokens as $token) {
            if ($token->is(\T_AS)) {
                $afterAlias = true;
            } elseif ($afterAlias) {
                $alias .= $token->text;
            } else {
                $name .= $token->text;
            }
        }

        $name = \ltrim($name, '\\');

        if ($alias === '') {
            $parts = \explode('\\', $name);
            $alias = $parts[\array_key_last($parts)];
        }

        if ($kind === \T_CONST) {
            $constants[$alias] = $name;
        } else {
            $imports[\strtolower($alias)] = $name;
        }
    }
}

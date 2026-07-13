<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Ignore;

use Greenlight\Core\ErrorTrap;

/**
 * Computes the set of source lines a file opts out of coverage.
 *
 * ignoredLines() tokenises the file and honours four marker forms: a
 * CoverageIgnore attribute or a comment containing "@codeCoverageIgnore"
 * before a named class-like or function declaration ignores the whole
 * declaration, signature through closing brace; a
 * "@codeCoverageIgnoreStart" / "@codeCoverageIgnoreEnd" comment pair
 * ignores the enclosed range, with an unmatched start running to end of
 * file and a stray end doing nothing; and a "@codeCoverageIgnore" comment
 * with no following declaration ignores its own line.
 *
 * The attribute is matched by name, bare or qualified, so no user code is
 * loaded; an import alias does not match. Unreadable files yield an empty
 * set, never an error.
 *
 * @internal
 */
final readonly class IgnoreScanner
{
    private const string START = '@codeCoverageIgnoreStart';
    private const string END = '@codeCoverageIgnoreEnd';
    private const string IGNORE = '@codeCoverageIgnore';
    private const string ATTRIBUTE = 'CoverageIgnore';

    /**
     * @return array<int, true> ignored line numbers as a set
     */
    public function ignoredLines(string $path): array
    {
        $source = ErrorTrap::run(static fn(): string|false => \file_get_contents($path), $warning);

        if (!\is_string($source)) {
            return [];
        }

        $tokens = \array_values(\PhpToken::tokenize($source));
        $count = \count($tokens);
        $ignored = [];
        $rangeStart = null;

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is([\T_COMMENT, \T_DOC_COMMENT])) {
                if (\str_contains($token->text, self::START)) {
                    $rangeStart ??= $token->line;
                } elseif (\str_contains($token->text, self::END)) {
                    if ($rangeStart !== null) {
                        $this->addRange($ignored, $rangeStart, $this->lastLine($token));
                        $rangeStart = null;
                    }
                } elseif (\str_contains($token->text, self::IGNORE)) {
                    $declaration = $this->declarationRange($tokens, $i + 1);

                    if ($declaration === null) {
                        $ignored[$token->line] = true;
                    } else {
                        $this->addRange($ignored, $declaration[0], $declaration[1]);
                    }
                }

                continue;
            }

            if ($token->is(\T_ATTRIBUTE)) {
                [$matched, $after] = $this->attributeGroup($tokens, $i);

                if ($matched) {
                    $declaration = $this->declarationRange($tokens, $after);

                    if ($declaration !== null) {
                        $this->addRange($ignored, $declaration[0], $declaration[1]);
                    }
                }

                $i = $after - 1;
            }
        }

        if ($rangeStart !== null) {
            $this->addRange($ignored, $rangeStart, \substr_count($source, "\n") + 1);
        }

        return $ignored;
    }

    /**
     * Finds the named declaration starting at or after $from, skipping
     * comments, attributes, and modifiers. Returns its first and last line,
     * or null when the next significant code is not a named class-like or
     * function declaration.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return array{int, int}|null
     */
    private function declarationRange(array $tokens, int $from): ?array
    {
        $count = \count($tokens);
        $i = $from;

        while ($i < $count) {
            $token = $tokens[$i];

            if ($token->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                $i++;

                continue;
            }

            if ($token->is(\T_ATTRIBUTE)) {
                [, $i] = $this->attributeGroup($tokens, $i);

                continue;
            }

            if ($token->is([\T_FINAL, \T_ABSTRACT, \T_READONLY, \T_STATIC, \T_PUBLIC, \T_PROTECTED, \T_PRIVATE])) {
                $i++;

                continue;
            }

            if ($token->is([\T_CLASS, \T_TRAIT, \T_INTERFACE, \T_ENUM])) {
                return $this->bodyRange($tokens, $i);
            }

            if ($token->is(\T_FUNCTION)) {
                return $this->isNamedFunction($tokens, $i) ? $this->bodyRange($tokens, $i) : null;
            }

            return null;
        }

        return null;
    }

    /**
     * @param list<\PhpToken> $tokens
     */
    private function isNamedFunction(array $tokens, int $functionIndex): bool
    {
        $count = \count($tokens);

        for ($i = $functionIndex + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT]) || $token->text === '&') {
                continue;
            }

            return $token->is(\T_STRING);
        }

        return false;
    }

    /**
     * Line span from a declaration keyword to its matching closing brace, or
     * to the terminating semicolon for bodyless signatures. Strings and
     * comments are single tokens, so braces inside them never miscount.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return array{int, int}
     */
    private function bodyRange(array $tokens, int $declarationIndex): array
    {
        $count = \count($tokens);
        $first = $tokens[$declarationIndex]->line;
        $depth = 0;

        for ($i = $declarationIndex; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($depth === 0 && $token->text === ';') {
                return [$first, $token->line];
            }

            if ($token->text === '{' || $token->is([\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
            } elseif ($token->text === '}' && --$depth === 0) {
                return [$first, $token->line];
            }
        }

        return [$first, $this->lastLine($tokens[$count - 1])];
    }

    /**
     * Walks one "#[ ... ]" group. Reports whether any attribute in it is
     * named CoverageIgnore, bare or qualified, and the index just past the
     * closing bracket. Argument lists are skipped wholesale so their
     * contents cannot produce false matches.
     *
     * @param list<\PhpToken> $tokens
     *
     * @return array{bool, int}
     */
    private function attributeGroup(array $tokens, int $attributeIndex): array
    {
        $count = \count($tokens);
        $depth = 1;
        $matched = false;
        $expectName = true;

        for ($i = $attributeIndex + 1; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token->text === '[' || $token->is(\T_ATTRIBUTE)) {
                $depth++;

                continue;
            }

            if ($token->text === ']') {
                if (--$depth === 0) {
                    return [$matched, $i + 1];
                }

                continue;
            }

            if ($depth !== 1 || $token->is([\T_WHITESPACE, \T_COMMENT, \T_DOC_COMMENT])) {
                continue;
            }

            if ($expectName && $token->is([\T_STRING, \T_NAME_QUALIFIED, \T_NAME_FULLY_QUALIFIED])) {
                $slash = \strrpos($token->text, '\\');
                $name = $slash === false ? $token->text : \substr($token->text, $slash + 1);

                if ($name === self::ATTRIBUTE) {
                    $matched = true;
                }

                $expectName = false;

                continue;
            }

            if ($token->text === ',') {
                $expectName = true;

                continue;
            }

            if ($token->text === '(') {
                $parens = 1;

                while (++$i < $count && $parens > 0) {
                    if ($tokens[$i]->text === '(') {
                        $parens++;
                    } elseif ($tokens[$i]->text === ')') {
                        $parens--;
                    }
                }

                $i--;
                $expectName = false;
            }
        }

        return [$matched, $count];
    }

    /**
     * @param array<int, true> $ignored
     */
    private function addRange(array &$ignored, int $first, int $last): void
    {
        for ($line = $first; $line <= $last; $line++) {
            $ignored[$line] = true;
        }
    }

    private function lastLine(\PhpToken $token): int
    {
        return $token->line + \substr_count($token->text, "\n");
    }
}

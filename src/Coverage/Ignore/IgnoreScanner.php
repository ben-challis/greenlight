<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Ignore;

use Greenlight\Core\ErrorTrap;

/**
 * Finds the source lines that coverage ignores in a file.
 *
 * ignoredLines() converts the file to tokens and accepts four marker forms.
 * A CoverageIgnore attribute can occur before a named class-like or function
 * declaration. A comment with "@codeCoverageIgnore" can occur in the same
 * position. These markers ignore the complete declaration.
 *
 * A pair of "@codeCoverageIgnoreStart" and "@codeCoverageIgnoreEnd" comments
 * ignores its range. A start marker without an end marker ignores to the
 * end of the file. An end marker without a start marker has no effect. An
 * "@codeCoverageIgnore" comment without a declaration ignores its own line.
 *
 * The scanner compares a bare or qualified attribute name. It does not load
 * user code. An import alias does not match. An unreadable file gives an
 * empty set, not an error.
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
        if (\str_contains($path, "\0")) {
            return [];
        }

        $source = ErrorTrap::run(static fn() => \file_get_contents($path), $warning);

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
                $rangeMarkers = $this->rangeMarkers($token->text);

                foreach ($rangeMarkers as $rangeMarker) {
                    if ($rangeMarker === self::START) {
                        $rangeStart ??= $token->line;
                    } elseif ($rangeStart !== null) {
                        $this->addRange($ignored, $rangeStart, $this->lastLine($token));
                        $rangeStart = null;
                    }
                }

                if ($rangeMarkers === [] && \str_contains($token->text, self::IGNORE)) {
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
     * @return list<string>
     */
    private function rangeMarkers(string $comment): array
    {
        $markers = [];

        foreach ([self::START, self::END] as $marker) {
            $offset = 0;

            while (($position = \strpos($comment, $marker, $offset)) !== false) {
                $markers[$position] = $marker;
                $offset = $position + \strlen($marker);
            }
        }

        \ksort($markers, \SORT_NUMERIC);

        return \array_values($markers);
    }

    /**
     * Finds the named declaration at or after $from.
     *
     * The search ignores comments, attributes, and modifiers. It returns the
     * first and last line. It returns null if the next applicable code is not
     * a named class-like or function declaration.
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
     * Finds the line range from a declaration keyword to its final character.
     *
     * The final character is the related final brace. For a signature
     * without a body, it is the final semicolon. Strings and comments are
     * single tokens. Thus, braces in them do not affect the count.
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
     * Examines one "#[ ... ]" group.
     *
     * Reports if it contains a bare or qualified CoverageIgnore attribute.
     * It also reports the index after the final bracket. The method does not
     * examine argument lists. Thus, their content cannot cause false matches.
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

                if (\strcasecmp($name, self::ATTRIBUTE) === 0) {
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

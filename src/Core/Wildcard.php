<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * Substring-or-wildcard matching for user-supplied filter patterns.
 *
 * A pattern without "*" or "?" matches by substring; a pattern containing
 * either must match the whole subject, shell-style.
 *
 * @internal
 */
final class Wildcard
{
    private function __construct() {}

    public static function matches(string $subject, string $pattern, bool $caseInsensitive): bool
    {
        if (!\str_contains($pattern, '*') && !\str_contains($pattern, '?')) {
            return $caseInsensitive
                ? \stripos($subject, $pattern) !== false
                : \str_contains($subject, $pattern);
        }

        $regex = '/^' . \strtr(\preg_quote($pattern, '/'), ['\*' => '.*', '\?' => '.']) . '$/' . ($caseInsensitive ? 'i' : '');

        return \preg_match($regex, $subject) === 1;
    }
}

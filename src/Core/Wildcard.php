<?php

declare(strict_types=1);

namespace Greenlight\Core;

/**
 * Compares a user filter pattern to part or all of the subject text.
 *
 * A pattern without "*" or "?" matches part of the subject text. A pattern
 * that has one of these characters must match all subject text as a shell
 * wildcard.
 *
 * @internal
 */
final class Wildcard
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function matches(string $subject, string $pattern, bool $caseInsensitive): bool
    {
        if (!\str_contains($pattern, '*') && !\str_contains($pattern, '?')) {
            return $caseInsensitive
                ? \preg_match('/' . \preg_quote($pattern, '/') . '/isu', $subject) === 1
                : \str_contains($subject, $pattern);
        }

        $regex = '/\A'
            . \strtr(\preg_quote($pattern, '/'), ['\*' => '.*', '\?' => '.'])
            . '\z/'
            . ($caseInsensitive ? 'isu' : 'su');

        return \preg_match($regex, $subject) === 1;
    }
}

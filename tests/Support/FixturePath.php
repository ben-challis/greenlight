<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

/** Builds validated paths inside the shared test fixture directory. */
final class FixturePath
{
    private function __construct() {}

    /**
     * @return non-empty-string
     */
    public static function get(string $relative): string
    {
        if ($relative === ''
            || \str_starts_with($relative, '/')
            || \str_contains($relative, '\\')
            || \str_contains($relative, "\0")
        ) {
            throw self::invalid($relative);
        }

        foreach (\explode('/', $relative) as $segment) {
            if (\in_array($segment, ['', '.', '..'], true)) {
                throw self::invalid($relative);
            }
        }

        return \dirname(__DIR__) . '/Fixture/' . $relative;
    }

    private static function invalid(string $relative): \InvalidArgumentException
    {
        return new \InvalidArgumentException(\sprintf(
            'Fixture path "%s" must be a relative path of plain segments.',
            $relative,
        ));
    }
}

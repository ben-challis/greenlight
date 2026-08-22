<?php

declare(strict_types=1);

namespace Greenlight\Coverage\Relay;

/**
 * Encodes coverage include paths for an environment variable.
 *
 * @internal
 */
final class CoverageRelayPaths
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @param list<non-empty-string> $paths
     */
    public static function encode(array $paths): string
    {
        $separator = '%' . \strtoupper(\bin2hex(\PATH_SEPARATOR));

        return \implode(\PATH_SEPARATOR, \array_map(
            static fn(string $path): string => \str_replace(
                ['%', \PATH_SEPARATOR, "\0"],
                ['%25', $separator, '%00'],
                $path,
            ),
            $paths,
        ));
    }

    /** @return list<non-empty-string> */
    public static function decode(string $encoded): array
    {
        $paths = [];

        foreach (\explode(\PATH_SEPARATOR, $encoded) as $path) {
            if ($path === '') {
                continue;
            }

            $paths[] = \rawurldecode($path);
        }

        return $paths;
    }
}

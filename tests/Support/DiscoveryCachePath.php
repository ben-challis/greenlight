<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

final class DiscoveryCachePath
{
    private function __construct() {}

    /**
     * @param list<non-empty-string> $directories
     *
     * @return non-empty-string
     */
    public static function forDirectories(array $directories): string
    {
        \sort($directories);

        return \sprintf(
            '%s/greenlight-discovery-%s.json',
            \rtrim(\sys_get_temp_dir(), '/'),
            \substr(\sha1(\implode("\n", $directories)), 0, 12),
        );
    }
}

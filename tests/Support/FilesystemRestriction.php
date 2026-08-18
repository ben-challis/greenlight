<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Expect\Fail;

final class FilesystemRestriction
{
    private function __construct() {}

    /** @param non-empty-string $projectRoot */
    public static function toProject(string $projectRoot): void
    {
        $temporaryDirectory = \sys_get_temp_dir();

        if ($temporaryDirectory === '') {
            Fail::because('The test could not resolve the system temporary directory.');
        }

        if (\ini_set('open_basedir', $projectRoot . \PATH_SEPARATOR . $temporaryDirectory) === false) {
            Fail::because('The test could not restrict file system access.');
        }
    }
}

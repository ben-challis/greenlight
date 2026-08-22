<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Expect\Fail;
use Greenlight\Internal\Php\ErrorTrap;

final class CoverageJson
{
    private function __construct() {}

    public static function read(string $path): CoverageMap
    {
        $json = ErrorTrap::run(
            static fn() => \file_get_contents($path),
            $warning,
        );

        if (!\is_string($json)) {
            Fail::because(\sprintf(
                'Expected to read coverage JSON document "%s"%s.',
                $path,
                $warning === null ? '' : ': ' . $warning,
            ));
        }

        return JsonExporter::import($json);
    }

    public static function write(string $path, CoverageMap $map): void
    {
        $documents = new JsonExporter()->export($map);
        $json = $documents[JsonExporter::FILE_NAME];
        $written = ErrorTrap::run(
            static fn() => \file_put_contents($path, $json),
            $warning,
        );

        if ($written !== \strlen($json)) {
            Fail::because(\sprintf(
                'Expected to write coverage JSON fixture "%s"%s.',
                $path,
                $warning === null ? '' : ': ' . $warning,
            ));
        }
    }
}

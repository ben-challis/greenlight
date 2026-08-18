<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Core\ErrorTrap;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\JsonExporter;
use Greenlight\Expect\Fail;

final class CoverageJson
{
    private function __construct() {}

    public static function write(string $path, CoverageMap $map): void
    {
        $documents = new JsonExporter()->export($map);
        $json = $documents[JsonExporter::FILE_NAME];
        $written = ErrorTrap::run(
            static fn(): int|false => \file_put_contents($path, $json),
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

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Config;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Config\WorkingDirectoryPath;

use function Greenlight\expect;

final class WorkingDirectoryPathTest
{
    #[Test]
    #[DataSet('paths')]
    public function resolveKeepsAbsolutePathsAndPrefixesRelativePaths(string $path, string $workingDirectory, string $expected): void
    {
        expect(WorkingDirectoryPath::resolve($path, $workingDirectory))->toBe($expected);
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function paths(): iterable
    {
        yield 'absolute path' => ['/etc/greenlight.php', '/project', '/etc/greenlight.php'];
        yield 'relative path' => ['tests', '/project', '/project/tests'];
        yield 'working directory with a trailing slash' => ['tests', '/project/', '/project/tests'];
        yield 'relative path with a trailing slash' => ['build/', '/project', '/project/build/'];
    }
}

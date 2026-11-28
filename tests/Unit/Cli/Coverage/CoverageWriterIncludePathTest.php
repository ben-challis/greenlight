<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Coverage\CoverageWriter;
use Greenlight\Cli\Output\Console;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Reporting\Style;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\MemoryStream;

use function Greenlight\expect;

final readonly class CoverageWriterIncludePathTest
{
    public function __construct(private Cleanup $cleanup, private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function aMissingIncludePathGivesAWarning(): void
    {
        $directory = $this->temporaryDirectory->path();
        \mkdir($directory . '/src');
        $stdout = MemoryStream::open();
        $stderr = MemoryStream::open();
        $this->cleanup->defer(static fn() => MemoryStream::close($stdout, $stderr));
        $err = '';
        $console = new Console(
            $stdout,
            $stderr,
            static function (string $text): void {},
            static function (string $text) use (&$err): void {
                $err .= $text;
            },
        );

        $result = new CoverageWriter($console)->write(
            new CoverageConfiguration(['src', 'sorce'], null, []),
            new CoverageMap([new FileCoverage($directory . '/src/A.php', [1], [])]),
            $directory,
            new Style(false),
        );

        expect($result)->because('a warning MUST NOT fail coverage without a gate')->toBeTrue();
        expect($err)
            ->because('a missing include path MUST be visible to the user')
            ->toBe("Warning: the coverage include path \"sorce\" does not exist. Greenlight measures no files from it.\n");
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Coverage\CoverageWriter;
use Greenlight\Cli\Output\Console;
use Greenlight\Config\CoverageConfiguration;
use Greenlight\Config\CoverageExport;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Reporting\Style;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;
use Greenlight\Tests\Support\MemoryStream;

use function Greenlight\expect;

final readonly class CoverageWriterStreamingTest
{
    public function __construct(private Cleanup $cleanup, private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function aWriteFailureStopsTheExportAndKeepsTheEarlierPages(): void
    {
        $root = $this->temporaryDirectory->path();
        $first = $root . '/A.php';
        $second = $root . '/B.php';
        \file_put_contents($first, "<?php\n\$a = 1;\n");
        \file_put_contents($second, "<?php\n\$b = 1;\n");
        $target = $root . '/html';
        $blocked = $target . '/' . HtmlExporter::pageName($second);
        \mkdir($blocked, 0o777, true);

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
            new CoverageConfiguration([], null, [new CoverageExport('html', $target)]),
            new CoverageMap([new FileCoverage($first, [2], []), new FileCoverage($second, [2], [])]),
            $root,
            new Style(false),
        );

        expect($result)->because('a failed page write MUST fail the export')->toBeFalse();
        expect($err)
            ->because('the message MUST name the page that Greenlight could not write')
            ->toContain(\sprintf('Greenlight could not write the coverage export to "%s"', $blocked));
        expect(\is_file($target . '/' . HtmlExporter::INDEX_FILE_NAME))
            ->because('the index comes first and MUST be on disk')
            ->toBeTrue();
        expect(\is_file($target . '/' . HtmlExporter::pageName($first)))
            ->because('pages before the failure MUST be on disk')
            ->toBeTrue();
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Tests\Fixture\Coverage\UnreadableSourceStream;

final readonly class HtmlExporterUnreadableSourceTest
{
    private const string SCHEME = 'greenlight-unreadable-source';

    public function __construct(private StreamWrappers $streamWrappers) {}

    #[Test]
    public function unreadableSourceFallsBackBeforeOpeningTheFile(): void
    {
        $this->streamWrappers->register(self::SCHEME, UnreadableSourceStream::class);

        $path = self::SCHEME . '://Source.php';
        UnreadableSourceStream::$openCalls = 0;

        Expect::that(\is_file($path))
            ->because('the fixture MUST be a regular file')
            ->toBeTrue();
        Expect::that(\is_readable($path))
            ->because('the fixture MUST reject reads')
            ->toBeFalse();

        $map = new CoverageMap([
            new FileCoverage($path, [2], [4]),
        ]);
        $page = new HtmlExporter()->export($map)[HtmlExporter::pageName($path)];

        Expect::that($page)
            ->because('an unreadable source shows only coverage line numbers')
            ->toContain('<span class="cov"><span class="num">2</span></span>')
            ->toContain('<span class="unc"><span class="num">4</span></span>');
        Expect::that(UnreadableSourceStream::$openCalls)
            ->because('the exporter rejects an unreadable source before opening it')
            ->toBe(0);
    }
}

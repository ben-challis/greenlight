<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Core\ErrorTrap;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Tests\Fixture\Coverage\UnreadableAfterStatStream;
use Greenlight\Tests\Support\FilesystemRestriction;

final readonly class HtmlExporterSourceFailureTest
{
    private const string SCHEME = 'greenlight-unreadable-after-stat';

    public function __construct(private StreamWrappers $streamWrappers) {}

    #[Test]
    #[Isolated]
    public function restrictedSourceFallsBackWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 3);
        $path = \dirname($root) . '/Source.php';
        FilesystemRestriction::toProject($root);

        $map = new CoverageMap([
            new FileCoverage($path, [2], [4]),
        ]);
        $pages = ErrorTrap::run(
            static fn() => new HtmlExporter()->export($map),
            $warning,
        );

        Expect::that($warning)
            ->because('a restricted source path MUST not leak engine diagnostics')
            ->toBeNull();
        Expect::that($pages[HtmlExporter::pageName($path)])
            ->because('a restricted source shows only coverage line numbers')
            ->toContain('<span class="cov"><span class="num">2</span></span>')
            ->toContain('<span class="unc"><span class="num">4</span></span>');
    }

    #[Test]
    public function sourceReadFailureFallsBackToLineNumbersOnly(): void
    {
        $this->streamWrappers->register(self::SCHEME, UnreadableAfterStatStream::class);

        $path = self::SCHEME . '://Source.php';
        $map = new CoverageMap([
            new FileCoverage($path, [2], [4]),
        ]);

        Expect::that(\is_file($path) && \is_readable($path))
            ->because('the source passes the exporter readability checks')
            ->toBeTrue();

        $pages = ErrorTrap::run(
            static fn() => new HtmlExporter()->export($map),
            $warning,
        );
        $page = $pages[HtmlExporter::pageName($path)];

        Expect::that($warning)
            ->because('a late source read failure MUST not leak an engine diagnostic')
            ->toBeNull();
        Expect::that($page)
            ->because('a late read failure shows only coverage line numbers')
            ->toContain('<span class="cov"><span class="num">2</span></span>')
            ->toContain('<span class="unc"><span class="num">4</span></span>');
    }
}

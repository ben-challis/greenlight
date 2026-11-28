<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Coverage\Export;

use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Coverage\Export\HtmlExporter;
use Greenlight\Coverage\FileCoverage;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class HtmlExporterStreamingTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    public function eachPageIsCreatedOnlyWhenTheCallerAsksForIt(): void
    {
        $root = $this->temporaryDirectory->path();
        $first = $root . '/A.php';
        $second = $root . '/B.php';
        \file_put_contents($first, "<?php\n\$first = 1;\n");
        \file_put_contents($second, "<?php\n\$staleSource = 1;\n");

        $pages = new HtmlExporter()->export(new CoverageMap([
            new FileCoverage($first, [2], []),
            new FileCoverage($second, [2], []),
        ]));

        expect($pages)->because('the exporter MUST give pages from a generator')->toBeInstanceOf(\Generator::class);

        expect($pages->key())->toBe(HtmlExporter::INDEX_FILE_NAME);
        $pages->next();
        expect($pages->key())->toBe(HtmlExporter::pageName($first));

        \file_put_contents($second, "<?php\n\$freshSource = 1;\n");
        $pages->next();

        expect($pages->key())->toBe(HtmlExporter::pageName($second));
        expect($pages->current())
            ->because('a page MUST NOT exist before the caller asks for it')
            ->toContain('freshSource')
            ->not()->toContain('staleSource');
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\DataRow;
use Greenlight\Attribute\Test;
use Greenlight\Coverage\CoverageMap;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\CoverageJson;
use Greenlight\Tests\Support\GreenlightCli;

final readonly class EmptyHtmlCoverageExportTest
{
    public function __construct(private TemporaryDirectory $temporaryDirectory) {}

    #[Test]
    #[DataRow([false], label: "new directory")]
    #[DataRow([true], label: "existing directory")]
    public function emptyCoverageWritesAnIndexInsideTheTargetDirectory(bool $existing): void
    {
        $directory = $this->temporaryDirectory->subdirectory("empty-html-coverage");
        CoverageJson::write($directory . "/one.json", CoverageMap::empty());
        CoverageJson::write($directory . "/two.json", CoverageMap::empty());

        if ($existing) {
            \mkdir($directory . "/html");
        }

        $result = GreenlightCli::run($directory, [
            "coverage:merge",
            "--input=one.json",
            "--input=two.json",
            "--export=html=html",
            "--no-ansi",
        ]);

        Expect::that($result->exitCode)->toBe(0);
        Expect::that(\is_dir($directory . "/html"))->toBeTrue();
        Expect::that((string) \file_get_contents($directory . "/html/index.html"))
            ->toContain("Greenlight Coverage")
            ->toContain("100.00%");
    }
}

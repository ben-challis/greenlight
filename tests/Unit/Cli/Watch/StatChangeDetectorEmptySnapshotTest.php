<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class StatChangeDetectorEmptySnapshotTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function firstPhpFileAfterAnEmptySnapshotIsReported(): void
    {
        $directory = $this->tempDirectory->subdirectory('empty-watch-directory');
        $detector = new StatChangeDetector([$directory]);

        Expect::that($detector->poll())
            ->because('the first poll MUST record an empty snapshot')
            ->toBe([]);

        $file = $directory . '/FirstTest.php';
        \file_put_contents($file, '<?php');

        Expect::that($detector->poll())
            ->because('the first PHP file MUST be reported after an empty snapshot')
            ->toBe([$file]);
    }
}

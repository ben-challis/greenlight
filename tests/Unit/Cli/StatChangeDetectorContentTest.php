<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;

final readonly class StatChangeDetectorContentTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function equalSizeRewriteWithRestoredModificationTimeIsReported(): void
    {
        $directory = $this->tempDirectory->subdirectory('watch-content');
        $source = $directory . '/ContentProbeTest.php';
        $original = '<?php // alpha';
        $replacement = '<?php // bravo';
        $mtime = 1_700_000_000;
        \file_put_contents($source, $original);

        if (!\touch($source, $mtime)) {
            Fail::because('Expected to set the initial source modification time.');
        }

        \clearstatcache(true, $source);
        $detector = new StatChangeDetector([$directory]);

        Expect::that($detector->poll())
            ->because('the first poll MUST only record the source fingerprint')
            ->toBe([]);

        \file_put_contents($source, $replacement);

        if (!\touch($source, $mtime)) {
            Fail::because('Expected to restore the source modification time.');
        }

        \clearstatcache(true, $source);

        Expect::that(\filemtime($source))
            ->because('the rewrite MUST preserve the modification time in the original fingerprint')
            ->toBe($mtime);
        Expect::that(\filesize($source))
            ->because('the rewrite MUST preserve the file size in the original fingerprint')
            ->toBe(\strlen($original));
        Expect::that($detector->poll())
            ->because('the content fingerprint MUST report an equal-size rewrite')
            ->toBe([$source]);
    }
}

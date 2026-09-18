<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class StatChangeDetectorContentTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

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

        expect($detector->poll())
            ->because('the first poll MUST only record the source fingerprint')
            ->toBe([]);

        \file_put_contents($source, $replacement);

        if (!\touch($source, $mtime)) {
            Fail::because('Expected to restore the source modification time.');
        }

        \clearstatcache(true, $source);

        expect(\filemtime($source))
            ->because('the rewrite MUST preserve the modification time in the original fingerprint')
            ->toBe($mtime);
        expect(\filesize($source))
            ->because('the rewrite MUST preserve the file size in the original fingerprint')
            ->toBe(\strlen($original));
        expect($detector->poll())
            ->because('the content fingerprint MUST report an equal-size rewrite')
            ->toBe([$source]);
    }
}

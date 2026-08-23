<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\TemporaryDirectory;

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
        $changes = $detector->poll();

        Expect::that($changes)
            ->because('the content fingerprint MUST report an equal-size rewrite')
            ->toHaveCount(1);
        Expect::that($changes[0]->path)->toBe($source);
    }

    #[Test]
    public function configuredContentRootsKeepBothSidesOfAChange(): void
    {
        $directory = $this->tempDirectory->subdirectory('watch-content-details');
        $source = $directory . '/ContentProbeTest.php';
        \file_put_contents($source, "<?php\nold\n");
        $detector = new StatChangeDetector([$directory], [$directory]);
        $detector->poll();
        \file_put_contents($source, "<?php\nnew\n");

        $changes = $detector->poll();

        Expect::that($changes)->toHaveCount(1);
        Expect::that($changes[0]->before)->toBe("<?php\nold\n");
        Expect::that($changes[0]->after)->toBe("<?php\nnew\n");
        Expect::that($changes[0]->changedLines())->toBe([2]);
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;

final readonly class TestDiscovererNonFileEntryTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aDirectoryLinkNamedLikeATestFileIsIgnored(): void
    {
        $scanned = $this->tempDirectory->subdirectory('scanned');
        $target = $this->tempDirectory->subdirectory('target');
        $link = $scanned . '/LinkedTest.php';

        if (!\symlink($target, $link)) {
            Fail::because('Expected to create the directory link fixture.');
        }

        Expect::that(new TestDiscoverer()->testFiles([$scanned]))
            ->because('test discovery MUST ignore directory links that resemble test files')
            ->toBe([]);
    }
}

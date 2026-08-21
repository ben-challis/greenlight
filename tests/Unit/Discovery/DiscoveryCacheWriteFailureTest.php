<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\DiscoveryCachePath;

final readonly class DiscoveryCacheWriteFailureTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function aCacheWriteFailureReturnsFalseWithoutChangingTheTarget(): void
    {
        $directory = $this->tempDirectory->subdirectory('cache-write-failure');
        $source = $directory . '/ExampleTest.php';
        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);
        \file_put_contents($source, '<?php');
        \mkdir($cacheFile);
        \file_put_contents($cacheFile . '/occupant.txt', 'keep');

        $cache = DiscoveryCache::forDirectories([$directory]);
        $cache->store($source, []);

        try {
            Expect::that($cache->persist())
                ->because('an advisory cache write failure MUST be reported to its caller')
                ->toBeFalse();
            Expect::that(\is_dir($cacheFile))
                ->because('the failed write MUST leave the target unchanged')
                ->toBeTrue();
            Expect::that((string) \file_get_contents($cacheFile . '/occupant.txt'))
                ->toBe('keep');
            Expect::that(\glob($cacheFile . '.tmp-*'))
                ->because('the failed write MUST remove its temporary file')
                ->toBe([]);
        } finally {
            @\unlink($cacheFile . '/occupant.txt');
            @\rmdir($cacheFile);
        }
    }

}

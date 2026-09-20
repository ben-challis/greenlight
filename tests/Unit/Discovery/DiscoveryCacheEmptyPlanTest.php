<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\DiscoveryCachePath;

use function Greenlight\expect;

final readonly class DiscoveryCacheEmptyPlanTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function anEmptyPlanRemainsAValidCacheHit(): void
    {
        $directory = $this->tempDirectory->subdirectory('empty-plan');
        $source = $directory . '/NoTests.php';
        \file_put_contents($source, '<?php');
        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);

        try {
            $cache = DiscoveryCache::forDirectories([$directory]);
            $cache->store($source, []);

            expect($cache->persist())
                ->because('an empty execution plan MUST be persisted')
                ->toBeTrue();
            expect(DiscoveryCache::forDirectories([$directory])->lookup($source))
                ->because('an empty execution plan is a valid cache hit, not corrupt data')
                ->toBe([]);
        } finally {
            @\unlink($cacheFile);
        }
    }
}

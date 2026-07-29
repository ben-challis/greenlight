<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

final readonly class DiscoveryCacheEmptyPlanTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function anEmptyPlanRemainsAValidCacheHit(): void
    {
        $directory = $this->tempDirectory->subdirectory('empty-plan');
        $source = $directory . '/NoTests.php';
        \file_put_contents($source, '<?php');
        $cacheFile = \sprintf(
            '%s/greenlight-discovery-%s.json',
            \rtrim(\sys_get_temp_dir(), '/'),
            \substr(\sha1($directory), 0, 12),
        );

        try {
            $cache = DiscoveryCache::forDirectories([$directory]);
            $cache->store($source, []);

            Expect::that($cache->persist())
                ->because('an empty execution plan MUST be persisted')
                ->toBeTrue()
                ->and(DiscoveryCache::forDirectories([$directory])->lookup($source))
                ->because('an empty execution plan is a valid cache hit, not corrupt data')
                ->toBe([]);
        } finally {
            @\unlink($cacheFile);
        }
    }
}

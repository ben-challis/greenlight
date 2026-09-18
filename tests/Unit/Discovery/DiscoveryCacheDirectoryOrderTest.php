<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\DiscoveryCachePath;
use Greenlight\Tests\Support\PlanEntryFixture;

use function Greenlight\expect;

final readonly class DiscoveryCacheDirectoryOrderTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function directoryOrderDoesNotChangeTheCacheIdentity(): void
    {
        $first = $this->tempDirectory->subdirectory('cache-order/first');
        $second = $this->tempDirectory->subdirectory('cache-order/second');
        $source = $first . '/OrderProbeTest.php';
        \file_put_contents($source, '<?php');

        $entry = PlanEntryFixture::create('Fixture\OrderProbeTest', 'probe');
        $directories = [$first, $second];
        $cacheFile = DiscoveryCachePath::forDirectories($directories);

        try {
            $cache = DiscoveryCache::forDirectories($directories);
            $cache->store($source, [$entry]);

            expect($cache->persist())
                ->because('the initial directory order MUST write the discovery cache')
                ->toBeTrue();
            expect(DiscoveryCache::forDirectories(\array_reverse($directories))->lookup($source))
                ->because('directory order MUST NOT change the discovery-cache identity')
                ->toEqual([$entry]);
        } finally {
            @\unlink($cacheFile);
        }
    }

}

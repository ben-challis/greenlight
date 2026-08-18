<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\DiscoveryCachePath;

final readonly class DiscoveryCacheDirectoryOrderTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function directoryOrderDoesNotChangeTheCacheIdentity(): void
    {
        $first = $this->tempDirectory->subdirectory('cache-order/first');
        $second = $this->tempDirectory->subdirectory('cache-order/second');
        $source = $first . '/OrderProbeTest.php';
        \file_put_contents($source, '<?php');

        $id = new TestId('Fixture\OrderProbeTest', 'probe');
        $entry = new PlanEntry(
            $id,
            new TestMetadata($id->class, $id->method),
        );
        $directories = [$first, $second];
        $cacheFile = DiscoveryCachePath::forDirectories($directories);

        try {
            $cache = DiscoveryCache::forDirectories($directories);
            $cache->store($source, [$entry]);

            Expect::that($cache->persist())
                ->because('the initial directory order MUST write the discovery cache')
                ->toBeTrue();
            Expect::that(DiscoveryCache::forDirectories(\array_reverse($directories))->lookup($source))
                ->because('directory order MUST NOT change the discovery-cache identity')
                ->toEqual([$entry]);
        } finally {
            @\unlink($cacheFile);
        }
    }

}

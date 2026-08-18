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

final readonly class DiscoveryCachePersistenceTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function unencodableMetadataDoesNotWriteACache(): void
    {
        $directory = $this->tempDirectory->subdirectory('unencodable-cache');
        $source = $directory . '/InvalidUtf8Test.php';
        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);
        \file_put_contents($source, '<?php');

        $id = new TestId('Example\InvalidUtf8Test', 'runs');
        $entry = new PlanEntry(
            $id,
            new TestMetadata($id->class, $id->method, groups: ["invalid \xFF"]),
        );
        $cache = DiscoveryCache::forDirectories([$directory]);
        $cache->store($source, [$entry]);

        try {
            Expect::that($cache->persist())
                ->because('unencodable metadata MUST make cache persistence fail safely')
                ->toBeFalse();
            Expect::that(\is_file($cacheFile))
                ->because('a failed cache encoding MUST not write a cache file')
                ->toBeFalse();
        } finally {
            @\unlink($cacheFile);
        }
    }

}

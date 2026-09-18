<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\Plan\PlanEntry;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\TestDefinition;
use Greenlight\Test\TestId;
use Greenlight\Tests\Support\DiscoveryCachePath;

use function Greenlight\expect;

final readonly class DiscoveryCachePersistenceTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function unencodableMetadataDoesNotWriteACache(): void
    {
        $directory = $this->tempDirectory->subdirectory('unencodable-cache');
        $source = $directory . '/InvalidUtf8Test.php';
        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);
        \file_put_contents($source, '<?php');

        $id = new TestId('Example\InvalidUtf8Test', 'runs');
        $entry = new PlanEntry(
            new TestDefinition($id->class, $id->method, groups: ["invalid \xFF"]),
        );
        $cache = DiscoveryCache::forDirectories([$directory]);
        $cache->store($source, [$entry]);

        try {
            expect($cache->persist())
                ->because('unencodable metadata MUST make cache persistence fail safely')
                ->toBeFalse();
            expect(\is_file($cacheFile))
                ->because('a failed cache encoding MUST not write a cache file')
                ->toBeFalse();
        } finally {
            @\unlink($cacheFile);
        }
    }

}

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

final readonly class DiscoveryCachePersistenceTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function unencodableMetadataDoesNotWriteACache(): void
    {
        $directory = $this->tempDirectory->subdirectory('unencodable-cache');
        $source = $directory . '/InvalidUtf8Test.php';
        $cacheFile = $this->cacheFile($directory);
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

    /**
     * @param non-empty-string $directory
     */
    private function cacheFile(string $directory): string
    {
        return \sprintf(
            '%s/greenlight-discovery-%s.json',
            \rtrim(\sys_get_temp_dir(), '/'),
            \substr(\sha1($directory), 0, 12),
        );
    }
}

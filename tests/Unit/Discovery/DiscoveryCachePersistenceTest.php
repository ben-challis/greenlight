<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;

final readonly class DiscoveryCachePersistenceTest
{
    #[Test]
    public function unencodableMetadataDoesNotWriteACache(): void
    {
        $directory = \sys_get_temp_dir() . '/greenlight-unencodable-cache-' . \bin2hex(\random_bytes(6));
        $source = $directory . '/InvalidUtf8Test.php';
        $cacheFile = $this->cacheFile($directory);
        \mkdir($directory, 0o777, true);
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
            @\unlink($source);
            @\rmdir($directory);
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

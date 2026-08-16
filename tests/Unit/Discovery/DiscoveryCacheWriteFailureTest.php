<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

final readonly class DiscoveryCacheWriteFailureTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aCacheWriteFailureReturnsFalseWithoutChangingTheTarget(): void
    {
        $directory = $this->tempDirectory->subdirectory('cache-write-failure');
        $source = $directory . '/ExampleTest.php';
        $cacheFile = $this->cacheFile($directory);
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

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Expect\Expect;

final readonly class DiscoveryCacheWriteFailureTest
{
    #[Test]
    public function aCacheWriteFailureReturnsFalseWithoutChangingTheTarget(): void
    {
        $directory = \sys_get_temp_dir() . '/greenlight-cache-write-failure-' . \bin2hex(\random_bytes(6));
        $source = $directory . '/ExampleTest.php';
        $cacheFile = $this->cacheFile($directory);
        \mkdir($directory, 0o777, true);
        \file_put_contents($source, '<?php');
        \mkdir($cacheFile);
        \file_put_contents($cacheFile . '/occupant.txt', 'keep');

        $cache = DiscoveryCache::forDirectories([$directory]);
        $cache->store($source, []);

        try {
            Expect::that($cache->persist())
                ->because('an advisory cache write failure MUST be reported to its caller')
                ->toBeFalse()
                ->and(\is_dir($cacheFile))
                ->because('the failed write MUST leave the target unchanged')
                ->toBeTrue()
                ->and((string) \file_get_contents($cacheFile . '/occupant.txt'))
                ->toBe('keep')
                ->and(\glob($cacheFile . '.tmp-*'))
                ->because('the failed write MUST remove its temporary file')
                ->toBe([]);
        } finally {
            @\unlink($cacheFile . '/occupant.txt');
            @\rmdir($cacheFile);
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

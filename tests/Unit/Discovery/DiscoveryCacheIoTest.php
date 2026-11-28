<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Filesystem\CountingFileStream;

use function Greenlight\expect;

final readonly class DiscoveryCacheIoTest
{
    private const string COUNTING_SCHEME = 'greenlight-counting-file';

    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private StreamWrappers $streamWrappers,
    ) {}

    #[Test]
    public function persistDoesNotRewriteACacheWithOnlyHits(): void
    {
        $directory = $this->tempDirectory->subdirectory('only-hits');
        $cacheDirectory = $this->tempDirectory->subdirectory('only-hits-cache');
        $source = $directory . '/OnlyHitsTest.php';
        \file_put_contents($source, '<?php');
        $cold = DiscoveryCache::forDirectories([$directory], $cacheDirectory);
        $cold->store($source, []);
        $cold->persist();
        $cacheFile = $this->cacheFile($cacheDirectory);

        $warm = DiscoveryCache::forDirectories([$directory], $cacheDirectory);
        $hit = $warm->lookup($source);
        \file_put_contents($cacheFile, 'sentinel');

        expect($hit)->toBe([]);
        expect($warm->persist())->toBeTrue();
        expect(\file_get_contents($cacheFile))
            ->because('persist() MUST not write a cache in which nothing changed')
            ->toBe('sentinel');
    }

    #[Test]
    public function eachPathIsHashedOnce(): void
    {
        $directory = $this->tempDirectory->subdirectory('hash-once');
        $source = self::COUNTING_SCHEME . '://HashOnceTest.php';
        $this->streamWrappers->register(self::COUNTING_SCHEME, CountingFileStream::class);
        CountingFileStream::$opens = [];
        $cache = DiscoveryCache::forDirectories([$directory], $this->tempDirectory->subdirectory('hash-once-cache'));

        $cache->store($source, []);
        $cache->store($source, []);

        expect(CountingFileStream::$opens)
            ->because('the cache MUST read the status of a path once for each object')
            ->toBe([$source => 1]);
    }

    private function cacheFile(string $cacheDirectory): string
    {
        $files = \glob($cacheDirectory . '/greenlight-discovery-*.json');

        if ($files === false || \count($files) !== 1) {
            Fail::because('The cache directory MUST contain one discovery cache file.');
        }

        return $files[0];
    }
}

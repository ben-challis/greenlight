<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\DiscoveryCachePath;

final readonly class DiscoveryCacheCorruptPlanTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function anUndecodablePlanEntryIsNotRetainedForPersistence(): void
    {
        $directory = $this->tempDirectory->subdirectory('corrupt-plan');
        $source = $directory . '/ExampleTest.php';
        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);
        \file_put_contents($source, '<?php');
        \file_put_contents($cacheFile, \json_encode([
            'version' => 5,
            'files' => [
                $source => [
                    'mtime' => \filemtime($source),
                    'size' => \filesize($source),
                    'entries' => [[]],
                    'dependencies' => [],
                    'contentHash' => \sha1_file($source),
                ],
            ],
        ], \JSON_THROW_ON_ERROR));
        $cache = DiscoveryCache::forDirectories([$directory]);

        try {
            Expect::that($cache->lookup($source))
                ->because('an undecodable plan entry MUST become a cache miss')
                ->toBeNull();

            \unlink($cacheFile);

            Expect::that($cache->persist())
                ->because('discarding a corrupt plan entry MUST leave no cache data to persist')
                ->toBeTrue();
            Expect::that(\is_file($cacheFile))
                ->because('persistence MUST NOT recreate a discarded corrupt plan entry')
                ->toBeFalse();
        } finally {
            @\unlink($cacheFile);
        }
    }

}

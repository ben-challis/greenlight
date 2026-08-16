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

final readonly class DiscoveryCacheInternalProviderTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function inheritedInternalProviderMethodsDoNotBlockCaching(): void
    {
        $directory = $this->tempDirectory->subdirectory('internal-provider');
        $source = $directory . '/ProbeTest.php';
        $providerSource = $directory . '/InternalProviderRows.php';
        $providerClass = 'GreenlightCacheInternalProvider\\InternalProviderRows';
        $cacheFile = $this->cacheFile($directory);
        \file_put_contents($source, "<?php\n");
        \file_put_contents($providerSource, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightCacheInternalProvider;

            final class InternalProviderRows extends \DateTimeZone {}
            PHP);
        require_once $providerSource;

        $entry = new PlanEntry(
            new TestId('Fixture\\ProbeTest', 'probe'),
            new TestMetadata(
                'Fixture\\ProbeTest',
                'probe',
                dataSetProvider: 'listAbbreviations',
                dataSetProviderClass: $providerClass,
            ),
        );

        try {
            $cache = DiscoveryCache::forDirectories([$directory]);
            $cache->store($source, [$entry]);

            Expect::that(new \ReflectionMethod($providerClass, 'listAbbreviations')->getFileName())
                ->because('the inherited provider method belongs to the PHP runtime')
                ->toBeFalse();
            Expect::that($cache->persist())
                ->because('an internal provider method MUST NOT block cache persistence')
                ->toBeTrue();

            $cached = DiscoveryCache::forDirectories([$directory])->lookup($source);
            $cachedWire = \array_map(
                static fn(PlanEntry $cachedEntry): array => $cachedEntry->toWire(),
                $cached ?? [],
            );

            Expect::that($cachedWire)
                ->because('the cache MUST retain an entry whose provider inherits an internal method')
                ->toBe([$entry->toWire()]);

            \file_put_contents($providerSource, "\n// changed\n", \FILE_APPEND);

            Expect::that(DiscoveryCache::forDirectories([$directory])->lookup($source))
                ->because('the cache MUST still track the user provider class source')
                ->toBeNull();
        } finally {
            @\unlink($cacheFile);
        }
    }

    private function cacheFile(string $directory): string
    {
        return \sprintf(
            '%s/greenlight-discovery-%s.json',
            \rtrim(\sys_get_temp_dir(), '/'),
            \substr(\sha1($directory), 0, 12),
        );
    }
}

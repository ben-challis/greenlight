<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\TestId;
use Greenlight\Core\Test\TestMetadata;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Tests\Support\DiscoveryCachePath;

final class DiscoveryCacheRuntimeProviderTest
{
    #[Test]
    public function runtimeProviderClassesDoNotPreventCacheHits(): void
    {
        $suffix = \bin2hex(\random_bytes(6));
        $provider = 'GreenlightRuntimeProvider\\Rows' . $suffix;
        $directory = \sys_get_temp_dir() . '/greenlight-runtime-provider-' . $suffix;
        $source = $directory . '/RuntimeProviderTest.php';
        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);

        eval(\sprintf(
            <<<'PHP'
                namespace GreenlightRuntimeProvider;

                final class Rows%s
                {
                    public static function rows(): iterable
                    {
                        yield 'one' => [1];
                    }
                }
                PHP,
            $suffix,
        ));
        \mkdir($directory, 0o777, true);
        \file_put_contents($source, '<?php');

        $id = new TestId('RuntimeProviderTest', 'probe', 'one');
        $entry = new PlanEntry(
            $id,
            new TestMetadata(
                $id->class,
                $id->method,
                dataSetProvider: 'rows',
                dataSetProviderClass: $provider,
            ),
        );

        try {
            $cache = DiscoveryCache::forDirectories([$directory]);
            $cache->store($source, [$entry]);

            Expect::that($cache->persist())
                ->because('a runtime provider has no dependency file')
                ->toBeTrue();
            Expect::that(DiscoveryCache::forDirectories([$directory])->lookup($source))
                ->because('the entry remains available from the cache')
                ->not()
                ->toBeNull();
        } finally {
            @\unlink($cacheFile);
            @\unlink($source);
            @\rmdir($directory);
        }
    }

}

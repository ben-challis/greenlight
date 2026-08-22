<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\DataProvider;
use Greenlight\Core\Test\TestDefinition;
use Greenlight\Core\Test\TestId;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\PlanEntry;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\DiscoveryCachePath;
use Greenlight\Tests\Support\PlanEntryFixture;

final readonly class DiscoveryCacheContentFingerprintTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function equalSizeRewriteWithRestoredModificationTimeInvalidatesTheEntry(): void
    {
        $directory = $this->tempDirectory->subdirectory('discovery-content');
        $source = $directory . '/ContentProbeTest.php';
        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);
        $original = '<?php // alpha';
        $replacement = '<?php // bravo';
        $mtime = 1_700_000_000;
        \file_put_contents($source, $original);

        if (!\touch($source, $mtime)) {
            Fail::because('Expected to set the initial source modification time.');
        }

        \clearstatcache(true, $source);
        $entry = PlanEntryFixture::create('Example\\ContentProbeTest');
        $cache = DiscoveryCache::forDirectories([$directory]);
        $cache->store($source, [$entry]);

        try {
            Expect::that($cache->persist())
                ->because('the discovery cache MUST persist the initial source fingerprint')
                ->toBeTrue();

            $warm = DiscoveryCache::forDirectories([$directory])->lookup($source);
            Expect::that(\array_map(
                static fn(PlanEntry $cached): array => $cached->toWire(),
                $warm ?? [],
            ))
                ->because('unchanged source content MUST use the cached plan entry')
                ->toBe([$entry->toWire()]);

            \file_put_contents($source, $replacement);

            if (!\touch($source, $mtime)) {
                Fail::because('Expected to restore the source modification time.');
            }

            \clearstatcache(true, $source);

            Expect::that(\filemtime($source))
                ->because('the rewrite MUST preserve the cached source modification time')
                ->toBe($mtime);
            Expect::that(\filesize($source))
                ->because('the rewrite MUST preserve the cached source file size')
                ->toBe(\strlen($original));
            Expect::that(DiscoveryCache::forDirectories([$directory])->lookup($source))
                ->because('the content fingerprint MUST invalidate an equal-size rewrite')
                ->toBeNull();
        } finally {
            @\unlink($cacheFile);
        }
    }

    #[Test]
    public function equalSizeProviderRewriteWithRestoredModificationTimeInvalidatesTheEntry(): void
    {
        $directory = $this->tempDirectory->subdirectory('discovery-provider-content');
        $source = $directory . '/ProviderProbeTest.php';
        $provider = $directory . '/ContentRows.php';
        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);
        $shortClass = 'ContentRows' . \bin2hex(\random_bytes(6));
        $providerClass = 'GreenlightDiscoveryFingerprint\\' . $shortClass;
        $providerSource = <<<PHP
            <?php

            declare(strict_types=1);

            namespace GreenlightDiscoveryFingerprint;

            final class {$shortClass}
            {
                /** @return iterable<string, array{int}> */
                public static function rows(): iterable
                {
                    yield 'alpha' => [1];
                }
            }
            PHP;
        $mtime = 1_700_000_000;
        \file_put_contents($source, "<?php\n");
        \file_put_contents($provider, $providerSource);

        if (!\touch($provider, $mtime)) {
            Fail::because('Expected to set the initial provider modification time.');
        }

        \clearstatcache(true, $provider);
        require_once $provider;
        $id = new TestId('Example\\ProviderProbeTest', 'runs');
        $entry = new PlanEntry(
            new TestDefinition(
                $id->class,
                $id->method,
                dataProvider: new DataProvider('rows', $providerClass),
            ),
        );
        $cache = DiscoveryCache::forDirectories([$directory]);
        $cache->store($source, [$entry]);

        try {
            Expect::that($cache->persist())
                ->because('the discovery cache MUST persist the provider fingerprint')
                ->toBeTrue();
            Expect::that(DiscoveryCache::forDirectories([$directory])->lookup($source))
                ->because('unchanged provider content MUST keep the cached plan entry')
                ->not()
                ->toBeNull();

            \file_put_contents($provider, \str_replace('alpha', 'bravo', $providerSource));

            if (!\touch($provider, $mtime)) {
                Fail::because('Expected to restore the provider modification time.');
            }

            \clearstatcache(true, $provider);

            Expect::that(\filemtime($provider))
                ->because('the rewrite MUST preserve the cached provider modification time')
                ->toBe($mtime);
            Expect::that(\filesize($provider))
                ->because('the rewrite MUST preserve the cached provider file size')
                ->toBe(\strlen($providerSource));
            Expect::that(DiscoveryCache::forDirectories([$directory])->lookup($source))
                ->because('the content fingerprint MUST invalidate an equal-size provider rewrite')
                ->toBeNull();
        } finally {
            @\unlink($cacheFile);
        }
    }

}

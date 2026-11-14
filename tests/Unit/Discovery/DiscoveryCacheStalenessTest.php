<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\Autoloaders;
use Greenlight\Sandbox\StreamWrappers;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Filesystem\CountingFileStream;

use function Greenlight\expect;

final readonly class DiscoveryCacheStalenessTest
{
    private const string COUNTING_SCHEME = 'greenlight-counting-file';

    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Autoloaders $autoloaders,
        private StreamWrappers $streamWrappers,
    ) {}

    #[Test]
    public function providerRowsFromAnUntrackedFileStayCurrent(): void
    {
        $suffix = \bin2hex(\random_bytes(6));
        $className = 'RowsProbe' . $suffix . 'Test';
        $directory = $this->tempDirectory->subdirectory('provider-rows');
        $cacheDirectory = $this->tempDirectory->subdirectory('provider-rows-cache');
        \file_put_contents($directory . '/rows.json', '["one"]');
        \file_put_contents($directory . '/' . $className . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace GreenlightCacheStaleness;

            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Test;

            final class {$className}
            {
                #[Test]
                #[DataSet('rows')]
                public function runs(string \$value): void {}

                public static function rows(): iterable
                {
                    foreach (\\json_decode((string) \\file_get_contents(__DIR__ . '/rows.json'), true) as \$name) {
                        yield \$name => [\$name];
                    }
                }
            }
            PHP);
        $this->autoload($directory, $className);

        $cold = new TestDiscoverer()->discover([$directory], cache: DiscoveryCache::forDirectories([$directory], $cacheDirectory));
        \file_put_contents($directory . '/rows.json', '["one","two"]');
        $warm = new TestDiscoverer()->discover([$directory], cache: DiscoveryCache::forDirectories([$directory], $cacheDirectory));

        expect(\count($cold))->toBe(1);
        expect(\count($warm))
            ->because('a cache hit MUST expand provider rows again')
            ->toBe(2);
    }

    #[Test]
    public function aClassInAnAttributeConstantExpressionIsTracked(): void
    {
        $suffix = \bin2hex(\random_bytes(6));
        $className = 'GroupProbe' . $suffix . 'Test';
        $cases = 'Cases' . $suffix;
        $directory = $this->tempDirectory->subdirectory('attribute-constant');
        $cacheDirectory = $this->tempDirectory->subdirectory('attribute-constant-cache');
        $support = $this->tempDirectory->subdirectory('attribute-constant-support');
        \file_put_contents($support . '/' . $cases . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace GreenlightCacheStaleness\\Support;

            final class {$cases}
            {
                public const string GROUP = 'fast';
            }
            PHP);
        \file_put_contents($directory . '/' . $className . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace GreenlightCacheStaleness;

            use Greenlight\Attribute\Group;
            use Greenlight\Attribute\Test;
            use GreenlightCacheStaleness\Support\\{$cases} as Cases;

            final class {$className}
            {
                #[Test]
                #[Group(Cases::GROUP)]
                public function runs(): void {}
            }
            PHP);
        $this->autoload($directory, $className);
        $this->autoloaders->register(static function (string $class) use ($support, $cases): void {
            if ($class === 'GreenlightCacheStaleness\\Support\\' . $cases) {
                require_once $support . '/' . $cases . '.php';
            }
        });

        new TestDiscoverer()->discover([$directory], cache: DiscoveryCache::forDirectories([$directory], $cacheDirectory));
        $source = \realpath($directory . '/' . $className . '.php');

        if ($source === false) {
            Fail::because('The test file MUST have a real path.');
        }

        $hit = DiscoveryCache::forDirectories([$directory], $cacheDirectory)->lookup($source);
        \file_put_contents($support . '/' . $cases . '.php', "\n// changed", \FILE_APPEND);
        $afterChange = DiscoveryCache::forDirectories([$directory], $cacheDirectory)->lookup($source);

        expect($hit)->not()->toBeNull();
        expect($afterChange)
            ->because('a change to a class in an attribute constant expression MUST make the entry stale')
            ->toBeNull();
    }

    #[Test]
    public function aCacheFromAnotherBuildIsIgnored(): void
    {
        $directory = $this->tempDirectory->subdirectory('identity');
        $cacheDirectory = $this->tempDirectory->subdirectory('identity-cache');
        $source = $directory . '/IdentityTest.php';
        \file_put_contents($source, '<?php');
        $cache = DiscoveryCache::forDirectories([$directory], $cacheDirectory);
        $cache->store($source, []);
        $cache->persist();
        $cacheFile = $this->cacheFile($cacheDirectory);
        $document = \json_decode((string) \file_get_contents($cacheFile), true, 32, \JSON_THROW_ON_ERROR);
        \assert(\is_array($document));
        $document['identity'] = '7.4.0 0.0.1@other';
        \file_put_contents($cacheFile, \json_encode($document, \JSON_THROW_ON_ERROR));

        expect(DiscoveryCache::forDirectories([$directory], $cacheDirectory)->lookup($source))
            ->because('another PHP or Greenlight version MUST not reuse the cache')
            ->toBeNull();
    }

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

    private function autoload(string $directory, string $className): void
    {
        $this->autoloaders->register(static function (string $class) use ($directory, $className): void {
            if ($class === 'GreenlightCacheStaleness\\' . $className) {
                require_once $directory . '/' . $className . '.php';
            }
        });
    }
}

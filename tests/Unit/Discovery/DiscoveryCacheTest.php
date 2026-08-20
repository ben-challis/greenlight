<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\EnvironmentSandbox;
use Greenlight\Fixture\StreamWrapperSandbox;
use Greenlight\Tests\Fixture\Filesystem\StatableFileStream;
use Greenlight\Tests\Support\DiscoveryCachePath;

final readonly class DiscoveryCacheTest
{
    private const string STATABLE_FILE_SCHEME = 'greenlight-statable-file';

    public function __construct(
        private EnvironmentSandbox $environment,
        private StreamWrapperSandbox $streamWrappers,
    ) {}

    #[Test]
    public function hitsServeFromCacheAndAnyChangeInvalidates(): void
    {
        $directory = $this->writeFixture();

        \spl_autoload_register(static function (string $class) use ($directory): void {
            if ($class === 'GreenlightDiscoCache\\CachedProbeTest') {
                require_once $directory . '/CachedProbeTest.php';
            }
        });

        try {
            $cold = new TestDiscoverer()->discover([$directory], cache: DiscoveryCache::forDirectories([$directory]));
            Expect::that($cold->count())->toBe(2);

            // Add an entry to the cached payload without a file change. The entry
            // shows that the second discovery reads the discovery cache.
            $cacheFile = DiscoveryCachePath::forDirectories([$directory]);
            $decoded = \json_decode((string) \file_get_contents($cacheFile), true, 32, \JSON_THROW_ON_ERROR);
            \assert(\is_array($decoded) && \is_array($decoded['files']));
            $path = (string) \array_key_first($decoded['files']);
            $cachedFile = $decoded['files'][$path];
            \assert(\is_array($cachedFile) && \is_array($cachedFile['entries']));
            $planted = $cachedFile['entries'][0];
            \assert(\is_array($planted) && \is_array($planted['id']) && \is_array($planted['metadata']));
            $planted['id']['method'] = 'plantedFromCache';
            $planted['metadata']['method'] = 'plantedFromCache';
            $cachedFile['entries'][] = $planted;
            $decoded['files'][$path] = $cachedFile;
            \file_put_contents($cacheFile, \json_encode($decoded));

            $warm = new TestDiscoverer()->discover([$directory], cache: DiscoveryCache::forDirectories([$directory]));
            Expect::that($warm->count())->toBe(3);

            // Change the file content and size. Discovery MUST parse the file again
            // and remove the added entry.
            \file_put_contents($directory . '/CachedProbeTest.php', \str_replace(
                'public function two(): void {}',
                "public function two(): void {}\n\n    // changed",
                (string) \file_get_contents($directory . '/CachedProbeTest.php'),
            ));

            $reparsed = new TestDiscoverer()->discover([$directory], cache: DiscoveryCache::forDirectories([$directory]));
            Expect::that($reparsed->count())->toBe(2);

            // A corrupt discovery cache causes discovery to parse the file.
            \file_put_contents($cacheFile, 'not json');
            $recovered = new TestDiscoverer()->discover([$directory], cache: DiscoveryCache::forDirectories([$directory]));
            Expect::that($recovered->count())->toBe(2);
        } finally {
            @\unlink(DiscoveryCachePath::forDirectories([$directory]));
            @\unlink($directory . '/CachedProbeTest.php');
            @\rmdir($directory);
        }
    }

    #[Test]
    #[DataSet('structurallyInvalidCacheDocuments')]
    public function structurallyInvalidCacheDocumentsAreRebuilt(string $document): void
    {
        $className = 'ShapeProbe' . \bin2hex(\random_bytes(6)) . 'Test';
        $directory = $this->writeFixture($className);
        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);
        $loader = static function (string $class) use ($directory, $className): void {
            if ($class === 'GreenlightDiscoCache\\' . $className) {
                require_once $directory . '/' . $className . '.php';
            }
        };
        \spl_autoload_register($loader);
        \file_put_contents($cacheFile, $document);

        try {
            $plan = new TestDiscoverer()->discover(
                [$directory],
                cache: DiscoveryCache::forDirectories([$directory]),
            );
            $rewritten = (string) \file_get_contents($cacheFile);

            Expect::that($plan->count())
                ->because('an invalid cache document becomes a cache miss')
                ->toBe(2);
            Expect::that($rewritten)
                ->toContain('"version":3')
                ->toContain($className . '.php');
        } finally {
            \spl_autoload_unregister($loader);
            @\unlink($cacheFile);
            @\unlink($directory . '/' . $className . '.php');
            @\rmdir($directory);
        }
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function structurallyInvalidCacheDocuments(): iterable
    {
        yield 'scalar document' => ['null'];
        yield 'wrong version' => ['{"version":2,"files":{}}'];
        yield 'files is not a map' => ['{"version":3,"files":"invalid"}'];
        yield 'file path is not a string' => ['{"version":3,"files":{"0":{}}}'];
        yield 'file entry is not a map' => ['{"version":3,"files":{"/project/Test.php":"invalid"}}'];
    }

    #[Test]
    public function aCorruptCachedPlanEntryIsReparsedAndReplaced(): void
    {
        $className = 'WireProbe' . \bin2hex(\random_bytes(6)) . 'Test';
        $directory = $this->writeFixture($className);
        $source = \realpath($directory . '/' . $className . '.php');

        Expect::that($source)
            ->because('The discovery fixture MUST have a canonical path.')
            ->toBeString();

        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);
        $loader = static function (string $class) use ($directory, $className): void {
            if ($class === 'GreenlightDiscoCache\\' . $className) {
                require_once $directory . '/' . $className . '.php';
            }
        };
        \spl_autoload_register($loader);

        try {
            $mtime = \filemtime($source);
            $size = \filesize($source);

            Expect::that($mtime)
                ->because('The discovery fixture MUST have a modification time.')
                ->toBeInt();
            Expect::that($size)
                ->because('The discovery fixture MUST have a file size.')
                ->toBeInt();

            \file_put_contents($cacheFile, \json_encode([
                'version' => 3,
                'files' => [
                    $source => [
                        'mtime' => $mtime,
                        'size' => $size,
                        'entries' => [[]],
                        'dependencies' => [],
                    ],
                ],
            ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));

            $plan = new TestDiscoverer()->discover(
                [$directory],
                cache: DiscoveryCache::forDirectories([$directory]),
            );
            $rewritten = (string) \file_get_contents($cacheFile);

            Expect::that($plan->count())
                ->because('a corrupt cached plan entry becomes a cache miss')
                ->toBe(2);
            Expect::that($rewritten)
                ->because('discovery replaces the corrupt plan entry')
                ->not()
                ->toContain('"entries":[[]]');
        } finally {
            \spl_autoload_unregister($loader);
            @\unlink($cacheFile);
            @\unlink($source);
            @\rmdir($directory);
        }
    }

    #[Test]
    public function externalProviderFileChangesInvalidateTestEntries(): void
    {
        $directory = \sys_get_temp_dir() . '/greenlight-disco-' . \bin2hex(\random_bytes(6));
        \mkdir($directory, 0o777, true);
        $testFile = $directory . '/ExternalCachedProbeTest.php';
        $providerFile = $directory . '/ExternalRows.php';

        \file_put_contents($testFile, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightExternalDiscoCache;

            use Greenlight\Attribute\DataSet;
            use Greenlight\Attribute\Test;

            final class ExternalCachedProbeTest
            {
                #[Test]
                #[DataSet(ExternalRows::class, 'rows')]
                public function probe(int $value): void {}
            }
            PHP);
        \file_put_contents($providerFile, <<<'PHP'
            <?php

            declare(strict_types=1);

            namespace GreenlightExternalDiscoCache;

            final class ExternalRows
            {
                /** @return iterable<string, array{int}> */
                public static function rows(): iterable
                {
                    yield 'one' => [1];
                }
            }
            PHP);

        \spl_autoload_register(static function (string $class) use ($directory): void {
            $file = match ($class) {
                'GreenlightExternalDiscoCache\ExternalCachedProbeTest' => 'ExternalCachedProbeTest.php',
                'GreenlightExternalDiscoCache\ExternalRows' => 'ExternalRows.php',
                default => null,
            };

            if ($file !== null) {
                require_once $directory . '/' . $file;
            }
        });

        try {
            $cache = DiscoveryCache::forDirectories([$directory]);
            $cold = new TestDiscoverer()->discover([$directory], cache: $cache);
            Expect::that($cold->count())->toBe(1);

            $cacheFile = DiscoveryCachePath::forDirectories([$directory]);
            $decoded = \json_decode((string) \file_get_contents($cacheFile), true, 32, \JSON_THROW_ON_ERROR);
            \assert(\is_array($decoded) && \is_array($decoded['files']));
            $cachedPath = \array_find(
                \array_keys($decoded['files']),
                static fn($path): bool => \is_string($path) && \basename($path) === 'ExternalCachedProbeTest.php',
            );

            \assert($cachedPath !== null);
            $cachedFile = $decoded['files'][$cachedPath];
            \assert(\is_array($cachedFile) && \is_array($cachedFile['entries']));
            $planted = $cachedFile['entries'][0];
            \assert(\is_array($planted) && \is_array($planted['id']) && \is_array($planted['metadata']));
            $planted['id']['method'] = 'plantedFromCache';
            $planted['metadata']['method'] = 'plantedFromCache';
            $cachedFile['entries'][] = $planted;
            $decoded['files'][$cachedPath] = $cachedFile;
            \file_put_contents($cacheFile, \json_encode($decoded));

            $warm = new TestDiscoverer()->discover(
                [$directory],
                cache: DiscoveryCache::forDirectories([$directory]),
            );
            Expect::that($warm->count())->toBe(2);

            \file_put_contents($providerFile, \file_get_contents($providerFile) . "\n// changed");

            $reparsed = new TestDiscoverer()->discover(
                [$directory],
                cache: DiscoveryCache::forDirectories([$directory]),
            );
            Expect::that($reparsed->count())->toBe(1);
        } finally {
            @\unlink(DiscoveryCachePath::forDirectories([$directory]));
            @\unlink($testFile);
            @\unlink($providerFile);
            @\rmdir($directory);
        }
    }

    #[Test]
    public function persistWritesThroughATempFileAndLeavesNoneBehind(): void
    {
        // Use a class name that other tests in this file do not use. The tests
        // can share a worker. Discovery rejects a class that the autoloader
        // loaded from a different fixture directory.
        $className = 'PersistProbeTest';
        $directory = $this->writeFixture($className);
        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);

        \spl_autoload_register(static function (string $class) use ($directory, $className): void {
            if ($class === 'GreenlightDiscoCache\\' . $className) {
                require_once $directory . '/' . $className . '.php';
            }
        });

        try {
            new TestDiscoverer()->discover([$directory], cache: DiscoveryCache::forDirectories([$directory]));

            Expect::that(\is_file($cacheFile))->toBeTrue();
            Expect::that(\glob($cacheFile . '.tmp-*'))->toBe([]);
        } finally {
            @\unlink($cacheFile);
            @\unlink($directory . '/' . $className . '.php');
            @\rmdir($directory);
        }
    }

    #[Test]
    public function persistWhoseRenameFailsLeavesTheTargetUntouchedAndNoTempFile(): void
    {
        $className = 'RenameFailProbeTest';
        $directory = $this->writeFixture($className);
        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);

        \spl_autoload_register(static function (string $class) use ($directory, $className): void {
            if ($class === 'GreenlightDiscoCache\\' . $className) {
                require_once $directory . '/' . $className . '.php';
            }
        });

        // Put a nonempty directory at the discovery-cache path. The temporary
        // file write succeeds, but the final rename fails. This exercises the
        // failure path that MUST remove the temporary file.
        \mkdir($cacheFile);
        \file_put_contents($cacheFile . '/occupant.txt', 'keep');

        try {
            new TestDiscoverer()->discover([$directory], cache: DiscoveryCache::forDirectories([$directory]));

            Expect::that(\is_dir($cacheFile))->toBeTrue();
            Expect::that((string) \file_get_contents($cacheFile . '/occupant.txt'))->toBe('keep');
            Expect::that(\glob($cacheFile . '.tmp-*'))->toBe([]);
        } finally {
            @\unlink($cacheFile . '/occupant.txt');
            @\rmdir($cacheFile);
            @\unlink($directory . '/' . $className . '.php');
            @\rmdir($directory);
        }
    }

    #[Test]
    public function persistToAMissingDirectoryIsASilentNoOp(): void
    {
        $className = 'MissingDirProbeTest';
        $directory = $this->writeFixture($className);
        $missingDirectory = \sys_get_temp_dir() . '/greenlight-missing-' . \bin2hex(\random_bytes(6));

        \spl_autoload_register(static function (string $class) use ($directory, $className): void {
            if ($class === 'GreenlightDiscoCache\\' . $className) {
                require_once $directory . '/' . $className . '.php';
            }
        });

        $this->environment->set('TMPDIR', $missingDirectory);

        try {
            new TestDiscoverer()->discover([$directory], cache: DiscoveryCache::forDirectories([$directory]));

            Expect::that(\is_dir($missingDirectory))->toBeFalse();
        } finally {
            @\unlink($directory . '/' . $className . '.php');
            @\rmdir($directory);
        }
    }

    #[Test]
    public function aSourceThatVanishedBeforeCachingIsIgnored(): void
    {
        $directory = \sys_get_temp_dir() . '/greenlight-vanished-' . \bin2hex(\random_bytes(6));
        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);
        $cache = DiscoveryCache::forDirectories([$directory]);

        try {
            $cache->store($directory . '/GoneTest.php', []);

            Expect::that($cache->persist())
                ->because('a vanished discovery source MUST not make cache persistence fail')
                ->toBeTrue();
            Expect::that(\is_file($cacheFile))
                ->because('a vanished source MUST not create an empty cache document')
                ->toBeFalse();
        } finally {
            @\unlink($cacheFile);
        }
    }

    #[Test]
    public function anUnencodableSourcePathDisablesPersistenceCleanly(): void
    {
        $directory = \sys_get_temp_dir() . '/greenlight-binary-path-' . \bin2hex(\random_bytes(6));
        $source = self::STATABLE_FILE_SCHEME . "://Invalid-\xFF-Test.php";
        $cacheFile = DiscoveryCachePath::forDirectories([$directory]);
        $this->streamWrappers->register(self::STATABLE_FILE_SCHEME, StatableFileStream::class);

        $cache = DiscoveryCache::forDirectories([$directory]);

        try {
            $cache->store($source, []);

            Expect::that($cache->persist())
                ->because('an unencodable source path MUST disable advisory cache persistence cleanly')
                ->toBeFalse();
            Expect::that(\is_file($cacheFile))
                ->because('failed cache encoding MUST not leave a cache document')
                ->toBeFalse();
        } finally {
            @\unlink($cacheFile);
        }
    }

    /**
     * @return non-empty-string
     */
    private function writeFixture(string $className = 'CachedProbeTest'): string
    {
        $directory = \sys_get_temp_dir() . '/greenlight-disco-' . \bin2hex(\random_bytes(6));
        \mkdir($directory, 0o777, true);

        \file_put_contents($directory . '/' . $className . '.php', <<<PHP
            <?php

            declare(strict_types=1);

            namespace GreenlightDiscoCache;

            use Greenlight\Attribute\Test;

            final class {$className}
            {
                #[Test]
                public function one(): void {}

                #[Test]
                public function two(): void {}
            }
            PHP);

        return $directory;
    }
}

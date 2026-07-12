<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Discovery;

use Greenlight\Attribute\Test;
use Greenlight\Discovery\DiscoveryCache;
use Greenlight\Discovery\TestDiscoverer;
use Greenlight\Expect\Expect;

final class DiscoveryCacheTest
{
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

            // Prove the second discovery reads the cache, not the file: plant
            // an extra entry in the cached payload without touching the file.
            $cacheFile = $this->cacheFile($directory);
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

            // Touching the file (content change, so size shifts) must force a
            // re-parse that drops the planted entry.
            \file_put_contents($directory . '/CachedProbeTest.php', \str_replace(
                'public function two(): void {}',
                "public function two(): void {}\n\n    // changed",
                (string) \file_get_contents($directory . '/CachedProbeTest.php'),
            ));

            $reparsed = new TestDiscoverer()->discover([$directory], cache: DiscoveryCache::forDirectories([$directory]));
            Expect::that($reparsed->count())->toBe(2);

            // A corrupt cache file falls back to parsing.
            \file_put_contents($cacheFile, 'not json');
            $recovered = new TestDiscoverer()->discover([$directory], cache: DiscoveryCache::forDirectories([$directory]));
            Expect::that($recovered->count())->toBe(2);
        } finally {
            @\unlink($this->cacheFile($directory));
            @\unlink($directory . '/CachedProbeTest.php');
            @\rmdir($directory);
        }
    }

    #[Test]
    public function persistWritesThroughATempFileAndLeavesNoneBehind(): void
    {
        // A class name distinct from the other tests in this file: they may
        // share a worker process, and the discoverer rejects a class it has
        // already autoloaded from a different fixture directory.
        $className = 'PersistProbeTest';
        $directory = $this->writeFixture($className);
        $cacheFile = $this->cacheFile($directory);

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
        $cacheFile = $this->cacheFile($directory);

        \spl_autoload_register(static function (string $class) use ($directory, $className): void {
            if ($class === 'GreenlightDiscoCache\\' . $className) {
                require_once $directory . '/' . $className . '.php';
            }
        });

        // A non-empty directory squatting on the cache path makes the
        // temp-file write succeed but the final rename fail, exercising the
        // failure branch that must remove the temp file.
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
        $originalTmpDir = \getenv('TMPDIR');
        $missingDirectory = \sys_get_temp_dir() . '/greenlight-missing-' . \bin2hex(\random_bytes(6));

        \spl_autoload_register(static function (string $class) use ($directory, $className): void {
            if ($class === 'GreenlightDiscoCache\\' . $className) {
                require_once $directory . '/' . $className . '.php';
            }
        });

        \putenv('TMPDIR=' . $missingDirectory);

        try {
            new TestDiscoverer()->discover([$directory], cache: DiscoveryCache::forDirectories([$directory]));

            Expect::that(\is_dir($missingDirectory))->toBeFalse();
        } finally {
            if ($originalTmpDir === false) {
                \putenv('TMPDIR');
            } else {
                \putenv('TMPDIR=' . $originalTmpDir);
            }

            @\unlink($directory . '/' . $className . '.php');
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

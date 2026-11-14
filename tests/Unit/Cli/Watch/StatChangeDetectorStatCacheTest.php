<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class StatChangeDetectorStatCacheTest
{
    private const int OLD = 1_700_000_000;

    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function oldUnchangedFilesAreNeverHashed(): void
    {
        $directory = $this->tempDirectory->subdirectory('stat-cache-old');
        $this->writeOld($directory . '/OldTest.php', '<?php // old');
        $hashes = 0;
        $detector = $this->detector($directory, $hashes, now: static fn(): float => \microtime(true) + 60.0);

        $detector->poll();
        $detector->poll();

        expect($detector->poll())->toBe([]);
        expect($hashes)
            ->because('stat data is enough for a file that is not racy')
            ->toBe(0);
    }

    #[Test]
    public function aFileAddedToACachedDirectoryIsDetected(): void
    {
        $directory = $this->tempDirectory->subdirectory('stat-cache-added');
        $this->writeOld($directory . '/FirstTest.php', '<?php // first');
        $this->touchOld($directory);
        $hashes = 0;
        $detector = $this->detector($directory, $hashes, now: static fn(): float => \microtime(true) + 60.0);
        $detector->poll();
        $detector->poll();

        \file_put_contents($directory . '/SecondTest.php', '<?php // second');

        expect($detector->poll())
            ->because('a new entry changes the parent directory stat data, so the directory is listed again')
            ->toBe([$directory . '/SecondTest.php']);
    }

    #[Test]
    public function aContentEditIsDetectedThroughTheFileStat(): void
    {
        $directory = $this->tempDirectory->subdirectory('stat-cache-edit');
        $source = $directory . '/EditedTest.php';
        $this->writeOld($source, '<?php // before');
        $hashes = 0;
        $detector = $this->detector($directory, $hashes, now: static fn(): float => \microtime(true) + 60.0);
        $detector->poll();

        \file_put_contents($source, '<?php // after, longer');

        expect($detector->poll())->toBe([$source]);
        expect($hashes)
            ->because('the changed stat data shows the edit without a content hash')
            ->toBe(0);
    }

    #[Test]
    public function aSameSecondEditIsDetectedThroughTheRacyRule(): void
    {
        $directory = $this->tempDirectory->subdirectory('stat-cache-racy');
        \file_put_contents($directory . '/RacyTest.php', '<?php // racy');
        $contents = ['first content', 'second content'];
        $detector = new StatChangeDetector(
            [$directory],
            hashFile: static function (string $path) use (&$contents): string {
                return \sha1((string) \array_shift($contents));
            },
        );
        $detector->poll();

        // The stat data stays the same. Only the content hash shows the
        // second write.
        expect($detector->poll())
            ->because('a file from the current second MUST be compared by content')
            ->toBe([$directory . '/RacyTest.php']);
    }

    /**
     * @param non-empty-string $directory
     * @param \Closure(): float $now
     */
    private function detector(string $directory, int &$hashes, \Closure $now): StatChangeDetector
    {
        return new StatChangeDetector(
            [$directory],
            now: $now,
            hashFile: static function (string $path) use (&$hashes): string|false {
                ++$hashes;

                return \sha1_file($path);
            },
        );
    }

    private function writeOld(string $path, string $contents): void
    {
        \file_put_contents($path, $contents);
        $this->touchOld($path);
    }

    private function touchOld(string $path): void
    {
        if (!\touch($path, self::OLD)) {
            Fail::because(\sprintf('Expected to set an old modification time on %s.', $path));
        }

        \clearstatcache(true, $path);
    }
}

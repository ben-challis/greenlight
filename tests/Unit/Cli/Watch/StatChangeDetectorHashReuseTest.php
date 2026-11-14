<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli\Watch;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\TemporaryDirectory;

use function Greenlight\expect;

final readonly class StatChangeDetectorHashReuseTest
{
    private const int MTIME = 1_700_000_000;

    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function anUnchangedOldFileIsHashedOnlyOnce(): void
    {
        [$detector, $hashes] = $this->detector('watch-hash-old', now: \microtime(true) + 60.0);

        $detector->poll();
        $detector->poll();
        $detector->poll();

        expect($hashes())
            ->because('polls MUST reuse the hash while the file metadata is unchanged')
            ->toBe(1);
    }

    #[Test]
    public function aRecentlyWrittenFileIsHashedOnEachPoll(): void
    {
        [$detector, $hashes] = $this->detector('watch-hash-recent', now: \microtime(true));

        $detector->poll();
        $detector->poll();

        expect($hashes())
            ->because('the change time of a new file is inside the recent-write window')
            ->toBe(2);
    }

    /**
     * @return array{StatChangeDetector, \Closure(): int}
     */
    private function detector(string $name, float $now): array
    {
        $directory = $this->tempDirectory->subdirectory($name);
        $source = $directory . '/HashProbeTest.php';
        \file_put_contents($source, '<?php // probe');

        if (!\touch($source, self::MTIME)) {
            Fail::because('Expected to set the source modification time.');
        }

        $count = 0;
        $detector = new StatChangeDetector(
            [$directory],
            now: static fn(): float => $now,
            hashFile: static function (string $path) use (&$count): string|false {
                ++$count;

                return \sha1_file($path);
            },
        );

        return [$detector, static function () use (&$count): int {
            return $count;
        }];
    }
}

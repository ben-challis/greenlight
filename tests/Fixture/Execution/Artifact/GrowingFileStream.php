<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Artifact;

use Greenlight\Doubles\Fake;

/** Simulates a file that grows after the initial size check. */
final class GrowingFileStream implements Fake
{
    public mixed $context;
    public static int $initialSize = 8192;
    public static string $stagingDirectory = '';
    public static int $maximumStagedBytes = 0;

    private int $offset = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return $mode === 'rb';
    }

    public function stream_read(int $count): string
    {
        $parts = \glob(self::$stagingDirectory . '/*/attempt-*/*.part');

        foreach ($parts === false ? [] : $parts as $path) {
            \clearstatcache(true, $path);
            self::$maximumStagedBytes = \max(self::$maximumStagedBytes, (int) \filesize($path));
        }

        $bytes = \min($count, 32768 - $this->offset);
        $this->offset += $bytes;

        return \str_repeat('x', $bytes);
    }

    public function stream_eof(): bool
    {
        return $this->offset >= 32768;
    }

    /** @return array{mode: int, size: int, mtime: int} */
    public function stream_stat(): array
    {
        return ['mode' => 0100600, 'size' => self::$initialSize, 'mtime' => 1];
    }

    /** @return array{mode: int, size: int, mtime: int} */
    public function url_stat(string $path, int $flags): array
    {
        return $this->stream_stat();
    }
}

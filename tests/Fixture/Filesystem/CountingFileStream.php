<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Filesystem;

use Greenlight\Doubles\Fake;

/**
 * Serves a fixed one-byte file and counts each open operation by path.
 */
final class CountingFileStream implements Fake
{
    /**
     * @var array<string, int>
     */
    public static array $opens = [];

    public mixed $context;

    private bool $read = false;

    /**
     * @return array{mode: int, size: int, mtime: int}
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100600, 'size' => 1, 'mtime' => 1];
    }

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$opens[$path] = (self::$opens[$path] ?? 0) + 1;

        return true;
    }

    public function stream_read(int $count): string
    {
        if ($this->read) {
            return '';
        }

        $this->read = true;

        return 'x';
    }

    public function stream_eof(): bool
    {
        return $this->read;
    }

    /**
     * @return array{mode: int, size: int, mtime: int}
     */
    public function stream_stat(): array
    {
        return ['mode' => 0100600, 'size' => 1, 'mtime' => 1];
    }
}

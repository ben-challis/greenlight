<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Artifact;

use Greenlight\Doubles\Fake;

/**
 * Simulates a regular file that fails when its contents are read.
 */
final class FailingFileReadStream implements Fake
{
    public mixed $context;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        return $mode === 'rb';
    }

    public function stream_read(int $count): false
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    /**
     * @return array{mode: int, size: int, mtime: int}
     */
    public function stream_stat(): array
    {
        return self::stat();
    }

    /**
     * @return array{mode: int, size: int, mtime: int}
     */
    public function url_stat(string $path, int $flags): array
    {
        return self::stat();
    }

    /**
     * @return array{mode: int, size: int, mtime: int}
     */
    private static function stat(): array
    {
        return [
            'mode' => 0100600,
            'size' => 8,
            'mtime' => 1,
        ];
    }
}

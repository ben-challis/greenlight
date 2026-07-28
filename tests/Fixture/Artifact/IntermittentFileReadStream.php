<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Artifact;

use Greenlight\Doubles\Fake;

/**
 * Simulates a regular file that returns no bytes for its first read.
 */
final class IntermittentFileReadStream implements Fake
{
    public mixed $context;

    private const string CONTENT = 'evidence';

    private int $offset = 0;

    private int $reads = 0;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        $this->offset = 0;
        $this->reads = 0;

        return $mode === 'rb';
    }

    public function stream_read(int $count): string
    {
        if ($this->reads++ === 0) {
            return '';
        }

        $chunk = \substr(self::CONTENT, $this->offset, $count);
        $this->offset += \strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->offset >= \strlen(self::CONTENT);
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
            'size' => \strlen(self::CONTENT),
            'mtime' => 1,
        ];
    }
}

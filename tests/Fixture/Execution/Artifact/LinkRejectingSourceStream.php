<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Artifact;

use Greenlight\Doubles\Fake;

/**
 * Serves a regular file on a chosen device. `link()` rejects stream wrapper
 * paths, so a copier that tries to link this source must fall back to a copy.
 */
final class LinkRejectingSourceStream implements Fake
{
    public mixed $context;

    private static int $device = 0;

    private static string $content = '';

    private int $position = 0;

    public static function serve(int $device, string $content): void
    {
        self::$device = $device;
        self::$content = $content;
    }

    /** @return array<string, int> */
    public function url_stat(string $path, int $flags): array
    {
        return ['dev' => self::$device, 'mode' => 0o100600, 'size' => \strlen(self::$content)];
    }

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        $this->position = 0;

        return true;
    }

    public function stream_read(int $count): string
    {
        $chunk = \substr(self::$content, $this->position, $count);
        $this->position += \strlen($chunk);

        return $chunk;
    }

    public function stream_eof(): bool
    {
        return $this->position >= \strlen(self::$content);
    }

    public function stream_close(): void {}
}

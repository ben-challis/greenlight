<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Cli\Profile;

use Greenlight\Doubles\Fake;

/** Opens a stream that fails before the end of its input. */
final class FailedReadStream implements Fake
{
    public mixed $context;

    public static bool $closed = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$closed = false;
        return true;
    }

    public function stream_read(int $count): false
    {
        return false;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_close(): void
    {
        self::$closed = true;
    }
}

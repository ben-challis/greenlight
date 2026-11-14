<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\ProcessPool\Protocol;

use Greenlight\Doubles\Fake;

/**
 * Returns queued bytes in small reads, like a socket that receives a large
 * frame in parts. The stream never reports EOF.
 */
final class ChunkedStream implements Fake
{
    private const int READ_BYTES = 4_096;

    public static string $pending = '';

    public mixed $context;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        return true;
    }

    public function stream_read(int $count): string
    {
        $bytes = \substr(self::$pending, 0, \min($count, self::READ_BYTES));
        self::$pending = \substr(self::$pending, \strlen($bytes));

        return $bytes;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_set_option(
        int $option,
        int $argumentOne,
        ?int $argumentTwo,
    ): bool {
        return true;
    }
}

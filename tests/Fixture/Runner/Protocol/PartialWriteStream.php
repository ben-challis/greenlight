<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner\Protocol;

use Greenlight\Doubles\Fake;

/**
 * Simulates a stream that accepts at most two bytes from each write.
 */
final class PartialWriteStream implements Fake
{
    public mixed $context;

    public static string $written = '';

    public static int $writes = 0;

    private static bool $pause = false;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        self::$written = '';
        self::$writes = 0;
        self::$pause = false;

        return true;
    }

    public function stream_write(string $data): int
    {
        if (self::$pause) {
            self::$pause = false;

            return 0;
        }

        $chunk = \substr($data, 0, 2);
        self::$written .= $chunk;
        self::$writes++;
        self::$pause = \strlen($chunk) < \strlen($data);

        return \strlen($chunk);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_set_option(
        int $option,
        int $argumentOne,
        ?int $argumentTwo,
    ): bool {
        return true;
    }
}

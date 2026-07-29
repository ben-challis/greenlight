<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner\Protocol;

use Greenlight\Doubles\Fake;

/**
 * Accepts writes only while the stream is in blocking mode.
 */
final class BlockingWriteStream implements Fake
{
    public mixed $context;

    private static string $contents = '';

    private bool $blocking = true;

    public static function contents(): string
    {
        return self::$contents;
    }

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        self::$contents = '';

        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_write(string $data): int
    {
        if (!$this->blocking) {
            return 0;
        }

        self::$contents .= $data;

        return \strlen($data);
    }

    public function stream_eof(): bool
    {
        return false;
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
        if ($option === \STREAM_OPTION_BLOCKING) {
            $this->blocking = $argumentOne === 1;
        }

        return true;
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner\Protocol;

use Greenlight\Doubles\Fake;

/**
 * Keeps the stream open but cannot write bytes.
 */
final class ZeroWriteStream implements Fake
{
    public mixed $context;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        return true;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_set_option(
        int $option,
        int $argumentOne,
        ?int $argumentTwo,
    ): bool {
        return true;
    }
}

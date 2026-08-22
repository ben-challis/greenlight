<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\ProcessPool\Protocol;

use Greenlight\Doubles\Fake;

/**
 * Accepts writes and rejects the final flush.
 */
final class FlushFailureStream implements Fake
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
        return \strlen($data);
    }

    public function stream_flush(): bool
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

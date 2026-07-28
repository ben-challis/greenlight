<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Artifact;

use Greenlight\Doubles\Fake;

/**
 * Simulates an open stream that cannot make progress on a write.
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
}

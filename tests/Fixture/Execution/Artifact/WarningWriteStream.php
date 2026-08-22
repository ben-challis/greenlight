<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Artifact;

use Greenlight\Doubles\Fake;

/** Simulates a stream that emits a warning and cannot write bytes. */
final class WarningWriteStream implements Fake
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
        \trigger_error('The fixture could not write attachment bytes.', \E_USER_WARNING);

        return 0;
    }
}

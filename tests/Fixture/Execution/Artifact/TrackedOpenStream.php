<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Artifact;

use Greenlight\Doubles\Fake;

final class TrackedOpenStream implements Fake
{
    public mixed $context;

    private static int $closedStreams = 0;

    public static function reset(): void
    {
        self::$closedStreams = 0;
    }

    public static function closedStreams(): int
    {
        return self::$closedStreams;
    }

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        return true;
    }

    public function stream_close(): void
    {
        ++self::$closedStreams;
    }
}

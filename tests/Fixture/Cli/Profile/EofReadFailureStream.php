<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Cli\Profile;

use Greenlight\Doubles\Fake;
use Greenlight\Event\RunFinished;
use Greenlight\Internal\Event\EventCodec;
use Greenlight\Result\ResultSummary;

/** Returns a complete event, then reports a read error and end of input. */
final class EofReadFailureStream implements Fake
{
    public mixed $context;

    public static bool $closed = false;

    private int $reads = 0;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$closed = false;
        return true;
    }

    public function stream_read(int $count): string|false
    {
        if (++$this->reads === 1) {
            return EventCodec::encodeJsonLine(new RunFinished('partial', new ResultSummary(), 0.0, 1.0));
        }

        \trigger_error('The profile input read failed.', \E_USER_WARNING);
        return false;
    }

    public function stream_eof(): bool
    {
        return $this->reads > 1;
    }

    public function stream_close(): void
    {
        self::$closed = true;
    }
}

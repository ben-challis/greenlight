<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Event\Event;
use Greenlight\Event\TestFinished;
use Greenlight\Event\WorkerSpawned;
use Greenlight\Internal\Event\EventCodec;

/**
 * Reads JSONL only from standard output. Each nonempty line MUST contain a
 * supported envelope. It MUST also contain a known event tag and payload.
 */
final class JsonlEvents
{
    private function __construct() {}

    /**
     * @return list<Event>
     *
     * @throws \RuntimeException when stdout contains invalid Greenlight JSONL
     */
    public static function from(ProcessResult $result): array
    {
        $lines = $result->stdoutLines();

        if ($lines === []) {
            return [];
        }

        $events = [];

        foreach ($lines as $index => $line) {
            try {
                $events[] = EventCodec::decodeJsonLine($line);
            } catch (\Throwable $failure) {
                $code = $failure->getCode();

                throw new \RuntimeException(
                    \sprintf(
                        'Invalid Greenlight JSONL on stdout line %d: %s',
                        $index + 1,
                        $failure->getMessage(),
                    ),
                    \is_int($code) ? $code : 0,
                    $failure,
                );
            }
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    public static function finishedTestIds(ProcessResult $result): array
    {
        $ids = [];

        foreach (self::from($result) as $event) {
            if ($event instanceof TestFinished) {
                $ids[] = (string) $event->result->id;
            }
        }

        return $ids;
    }

    /**
     * @param list<Event> $events
     *
     * @return list<string>
     */
    public static function spawnedWorkerIds(array $events): array
    {
        $ids = [];

        foreach ($events as $event) {
            if ($event instanceof WorkerSpawned) {
                $ids[] = $event->workerId;
            }
        }

        return $ids;
    }
}

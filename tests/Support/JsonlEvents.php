<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\EventTags;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Wire\Wire;

/**
 * Reads JSONL only from standard output. Each line except a final empty line
 * MUST contain a valid version-two envelope. It MUST also contain a known
 * event tag and event payload.
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
                $envelope = \json_decode($line, true, 512, \JSON_THROW_ON_ERROR);

                if (!\is_array($envelope)) {
                    throw new \RuntimeException('Envelope must be a JSON object.');
                }

                $map = [];

                foreach ($envelope as $key => $value) {
                    $map[(string) $key] = $value;
                }

                $version = Wire::int($map, 'v');

                if ($version !== 2) {
                    throw new \RuntimeException(\sprintf('Unsupported version %d.', $version));
                }

                $tag = Wire::nonEmptyString($map, 'event');
                $eventClass = EventTags::classFor($tag);

                if ($eventClass === null) {
                    throw new \RuntimeException(\sprintf('Unknown event tag "%s".', $tag));
                }

                $events[] = $eventClass::fromWire(Wire::map($map, 'data'));
            } catch (\Throwable $failure) {
                throw new \RuntimeException(
                    \sprintf(
                        'Invalid Greenlight JSONL on stdout line %d: %s',
                        $index + 1,
                        $failure->getMessage(),
                    ),
                    $failure->getCode(),
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

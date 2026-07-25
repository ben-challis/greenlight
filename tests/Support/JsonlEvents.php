<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\EventTags;
use Greenlight\Core\Wire\Wire;

/**
 * Reads JSONL from stdout only. Every non-trailing line must contain a valid
 * version-one envelope, known event tag, and event payload.
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
        if ($result->stdout === '') {
            return [];
        }

        $lines = \explode("\n", $result->stdout);

        if ($lines[\array_key_last($lines)] === '') {
            \array_pop($lines);
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

                if ($version !== 1) {
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
}

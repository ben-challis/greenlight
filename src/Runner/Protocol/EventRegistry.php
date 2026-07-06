<?php

declare(strict_types=1);

namespace Greenlight\Runner\Protocol;

use Greenlight\Core\Event\Event;
use Greenlight\Core\Event\RunFinished;
use Greenlight\Core\Event\RunStarted;
use Greenlight\Core\Event\SuiteFinished;
use Greenlight\Core\Event\SuiteStarted;
use Greenlight\Core\Event\TestClassFinished;
use Greenlight\Core\Event\TestClassStarted;
use Greenlight\Core\Event\TestFinished;
use Greenlight\Core\Event\TestStarted;
use Greenlight\Core\Event\WorkerRecycled;
use Greenlight\Core\Event\WorkerSpawned;
use Greenlight\Core\Wire\Wire;

/**
 * Stable tag per event class for wire transport. Additive only: a tag, once
 * shipped, never changes meaning.
 *
 * @internal
 */
final class EventRegistry
{
    /**
     * @var array<non-empty-string, class-string<Event>>
     */
    private const array TAGS = [
        'run-started' => RunStarted::class,
        'run-finished' => RunFinished::class,
        'suite-started' => SuiteStarted::class,
        'suite-finished' => SuiteFinished::class,
        'class-started' => TestClassStarted::class,
        'class-finished' => TestClassFinished::class,
        'test-started' => TestStarted::class,
        'test-finished' => TestFinished::class,
        'worker-spawned' => WorkerSpawned::class,
        'worker-recycled' => WorkerRecycled::class,
    ];

    private function __construct() {}

    /**
     * @return array<string, mixed>
     */
    public static function toTagged(Event $event): array
    {
        $tag = \array_search($event::class, self::TAGS, true);

        if ($tag === false) {
            throw ProtocolError::unknownEvent($event::class);
        }

        return ['event' => $tag, 'data' => $event->toWire()];
    }

    /**
     * @param array<string, mixed> $tagged
     *
     * @throws ProtocolError
     */
    public static function fromTagged(array $tagged): Event
    {
        $tag = Wire::nonEmptyString($tagged, 'event');
        $class = self::TAGS[$tag] ?? null;

        if ($class === null) {
            throw ProtocolError::unknownEvent($tag);
        }

        return $class::fromWire(Wire::map($tagged, 'data'));
    }
}

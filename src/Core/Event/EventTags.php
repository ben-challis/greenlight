<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

/**
 * The canonical stable tag per event class, shared by every surface that
 * names events outside PHP (the wire protocol, machine-readable reporters).
 * Additive only: a tag, once shipped, never changes meaning.
 *
 * @internal
 */
final class EventTags
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
     * @return non-empty-string|null
     */
    public static function tagFor(Event $event): ?string
    {
        $tag = \array_search($event::class, self::TAGS, true);

        return $tag === false ? null : $tag;
    }

    /**
     * @return class-string<Event>|null
     */
    public static function classFor(string $tag): ?string
    {
        return self::TAGS[$tag] ?? null;
    }

    /**
     * @return array<non-empty-string, class-string<Event>>
     */
    public static function all(): array
    {
        return self::TAGS;
    }
}

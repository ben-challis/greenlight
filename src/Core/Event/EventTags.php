<?php

declare(strict_types=1);

namespace Greenlight\Core\Event;

/**
 * Defines one stable tag for each event class.
 *
 * The wire protocol and reporters for machine consumers use these tags outside PHP.
 * Add new tags only. Do not change what a published tag identifies.
 *
 * The registry contains suite-started and suite-finished as reserved tags.
 * No run emits these tags until execution has suite boundaries.
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

    /** @codeCoverageIgnore */
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

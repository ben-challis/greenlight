<?php

declare(strict_types=1);

namespace Greenlight\Event;

/**
 * Defines one stable tag for each event class.
 *
 * The wire protocol and reporters for machine consumers use these tags outside PHP.
 * A JSONL version fixes the public tag set for that version.
 *
 * @internal
 */
final class EventTags
{
    /**
     * @var array<non-empty-string, class-string<WireEvent>>
     */
    private const array TAGS = [
        'run-started' => RunStarted::class,
        'run-finished' => RunFinished::class,
        'class-started' => TestClassStarted::class,
        'class-finished' => TestClassFinished::class,
        'test-started' => TestStarted::class,
        'test-finished' => TestFinished::class,
        'worker-spawned' => WorkerSpawned::class,
    ];

    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @return non-empty-string|null
     */
    public static function tagFor(WireEvent $event): ?string
    {
        $tag = \array_search($event::class, self::TAGS, true);

        return $tag === false ? null : $tag;
    }

    /**
     * @return class-string<WireEvent>|null
     */
    public static function classFor(string $tag): ?string
    {
        return self::TAGS[$tag] ?? null;
    }

    /**
     * @return array<non-empty-string, class-string<WireEvent>>
     */
    public static function all(): array
    {
        return self::TAGS;
    }
}

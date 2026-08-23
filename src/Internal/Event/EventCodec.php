<?php

declare(strict_types=1);

namespace Greenlight\Internal\Event;

use Greenlight\Event\Event;
use Greenlight\Event\RunFinished;
use Greenlight\Event\RunStarted;
use Greenlight\Event\TestClassFinished;
use Greenlight\Event\TestClassStarted;
use Greenlight\Event\TestFinished;
use Greenlight\Event\TestStarted;
use Greenlight\Event\WireEvent;
use Greenlight\Event\WorkerSpawned;
use Greenlight\Internal\Wire\Wire;
use Greenlight\Internal\Wire\WireCommunicationFailed;

/**
 * Encodes and decodes built-in events for the worker protocol and JSONL streams.
 *
 * The JSONL encoder writes version 3. The decoder accepts versions 2 and 3.
 *
 * @internal
 */
final class EventCodec
{
    private const int JSONL_VERSION = 3;

    /** @var list<int> */
    private const array ACCEPTED_JSONL_VERSIONS = [2, 3];

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

    private function __construct() {}

    /**
     * @return array<non-empty-string, class-string<WireEvent>>
     */
    public static function tags(): array
    {
        return self::TAGS;
    }

    /**
     * @return array{event: non-empty-string, data: array<string, mixed>}
     *
     * @throws EventCodecFailed
     */
    public static function toTagged(Event $event): array
    {
        if (!$event instanceof WireEvent) {
            throw EventCodecFailed::unmappedEvent($event::class);
        }

        $tag = \array_search($event::class, self::TAGS, true);

        if ($tag === false) {
            throw EventCodecFailed::unmappedEvent($event::class);
        }

        return ['event' => $tag, 'data' => $event->toWire()];
    }

    /**
     * @param array<string, mixed> $tagged
     *
     * @throws EventCodecFailed
     */
    public static function fromTagged(array $tagged): WireEvent
    {
        try {
            $tag = Wire::nonEmptyString($tagged, 'event');
        } catch (WireCommunicationFailed $failure) {
            throw EventCodecFailed::malformedTaggedPayload($failure);
        }

        $class = self::eventClass($tag);

        try {
            $data = Wire::map($tagged, 'data');
        } catch (WireCommunicationFailed $failure) {
            throw EventCodecFailed::malformedTaggedPayload($failure);
        }

        return self::eventFrom($tag, $class, $data);
    }

    /**
     * @throws EventCodecFailed
     */
    public static function encodeJsonLine(Event $event): string
    {
        $tagged = self::toTagged($event);

        try {
            $json = \json_encode(
                ['v' => self::JSONL_VERSION, ...$tagged],
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_INVALID_UTF8_SUBSTITUTE,
            );
        } catch (\JsonException $failure) {
            throw EventCodecFailed::jsonEncodingFailed($failure);
        }

        return $json . "\n";
    }

    /**
     * @throws EventCodecFailed
     */
    public static function decodeJsonLine(string $line): WireEvent
    {
        try {
            $decoded = \json_decode($line, true, 32, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $failure) {
            throw EventCodecFailed::malformedJson($failure);
        }

        if (!\is_array($decoded) || ($decoded !== [] && \array_is_list($decoded))) {
            throw EventCodecFailed::malformedJsonEnvelope();
        }

        $envelope = [];

        foreach ($decoded as $key => $value) {
            if (!\is_string($key)) {
                throw EventCodecFailed::malformedJsonEnvelope();
            }

            $envelope[$key] = $value;
        }

        try {
            $version = Wire::int($envelope, 'v');
            $tag = Wire::nonEmptyString($envelope, 'event');
            $data = Wire::map($envelope, 'data');
        } catch (WireCommunicationFailed $failure) {
            throw EventCodecFailed::malformedJsonEnvelope($failure);
        }

        if (!\in_array($version, self::ACCEPTED_JSONL_VERSIONS, true)) {
            throw EventCodecFailed::unsupportedJsonVersion($version);
        }

        return self::eventFrom($tag, self::eventClass($tag), $data);
    }

    /**
     * @param non-empty-string $tag
     *
     * @return class-string<WireEvent>
     * @throws EventCodecFailed
     */
    private static function eventClass(string $tag): string
    {
        $class = self::TAGS[$tag] ?? null;

        if ($class === null) {
            throw EventCodecFailed::unknownEvent($tag);
        }

        return $class;
    }

    /**
     * @param non-empty-string $tag
     * @param class-string<WireEvent> $class
     * @param array<string, mixed> $data
     *
     * @throws EventCodecFailed
     */
    private static function eventFrom(string $tag, string $class, array $data): WireEvent
    {
        try {
            return $class::fromWire($data);
        } catch (WireCommunicationFailed|\InvalidArgumentException $failure) {
            throw EventCodecFailed::invalidEventPayload($tag, $failure);
        }
    }
}

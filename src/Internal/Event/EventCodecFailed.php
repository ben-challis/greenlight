<?php

declare(strict_types=1);

namespace Greenlight\Internal\Event;

/**
 * Reports that the event codec could not encode or decode an event.
 *
 * @internal
 */
final class EventCodecFailed extends \RuntimeException
{
    private function __construct(
        public readonly EventCodecFailureKind $kind,
        string $message,
        public readonly ?string $eventIdentifier = null,
        public readonly ?int $jsonVersion = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, previous: $previous);
    }

    /** @param class-string $eventClass */
    public static function unmappedEvent(string $eventClass): self
    {
        return new self(
            EventCodecFailureKind::UnmappedEvent,
            \sprintf('Event "%s" has no stable tag.', $eventClass),
            eventIdentifier: $eventClass,
        );
    }

    public static function unknownEvent(string $tag): self
    {
        return new self(
            EventCodecFailureKind::UnknownEvent,
            \sprintf('Unknown event type "%s".', $tag),
            eventIdentifier: $tag,
        );
    }

    public static function malformedTaggedPayload(\Throwable $previous): self
    {
        return new self(
            EventCodecFailureKind::MalformedTaggedPayload,
            $previous->getMessage(),
            previous: $previous,
        );
    }

    public static function malformedJson(\JsonException $previous): self
    {
        return new self(
            EventCodecFailureKind::MalformedJson,
            'The JSONL line is not valid JSON.',
            previous: $previous,
        );
    }

    public static function malformedJsonEnvelope(?\Throwable $previous = null): self
    {
        return new self(
            EventCodecFailureKind::MalformedJsonEnvelope,
            'The JSONL line does not contain an event envelope.',
            previous: $previous,
        );
    }

    public static function unsupportedJsonVersion(int $version): self
    {
        return new self(
            EventCodecFailureKind::UnsupportedJsonVersion,
            \sprintf('Unsupported JSONL version %d.', $version),
            jsonVersion: $version,
        );
    }

    public static function invalidEventPayload(string $tag, \Throwable $previous): self
    {
        return new self(
            EventCodecFailureKind::InvalidEventPayload,
            $previous->getMessage(),
            eventIdentifier: $tag,
            previous: $previous,
        );
    }

    public static function jsonEncodingFailed(\JsonException $previous): self
    {
        return new self(
            EventCodecFailureKind::JsonEncodingFailed,
            'Greenlight could not encode the event as JSON: ' . $previous->getMessage() . '.',
            previous: $previous,
        );
    }
}

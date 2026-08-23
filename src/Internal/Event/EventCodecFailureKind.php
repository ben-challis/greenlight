<?php

declare(strict_types=1);

namespace Greenlight\Internal\Event;

/**
 * Identifies one event codec failure so that each caller can report it at its seam.
 *
 * @internal
 */
enum EventCodecFailureKind
{
    case UnmappedEvent;
    case UnknownEvent;
    case MalformedTaggedPayload;
    case MalformedJson;
    case MalformedJsonEnvelope;
    case UnsupportedJsonVersion;
    case InvalidEventPayload;
    case JsonEncodingFailed;
}

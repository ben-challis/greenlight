<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * Raised when a reporter cannot render or deliver its output, for example
 * when the underlying stream rejects a write or an event has no wire tag.
 *
 * @internal
 */
final class ReportingError extends \RuntimeException
{
    public static function writeFailed(): self
    {
        return new self('Could not write reporter output to the underlying stream.');
    }

    /**
     * @param class-string $eventClass
     */
    public static function unmappedEvent(string $eventClass): self
    {
        return new self(\sprintf('Event "%s" has no stable tag. Add it to the tag map before emitting it.', $eventClass));
    }

    public static function xmlUnavailable(): self
    {
        return new self('The XMLWriter extension is required for JUnit output. Enable ext-xmlwriter.');
    }
}

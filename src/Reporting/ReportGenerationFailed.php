<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * A reporter could not render or deliver its required output.
 */
final class ReportGenerationFailed extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function writeFailed(?\Throwable $previous = null): self
    {
        return new self('Greenlight did not write reporter output to the stream.', $previous);
    }

    /**
     * @param class-string $eventClass
     */
    public static function unmappedEvent(string $eventClass): self
    {
        return new self(\sprintf('Event "%s" has no stable tag. Add the event to the tag map before Greenlight writes it.', $eventClass));
    }

    public static function xmlUnavailable(): self
    {
        return new self('The XMLWriter extension is required for JUnit output. Enable ext-xmlwriter.');
    }
}

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

    /**
     * Creates a failure for a reporter implementation.
     *
     * @param non-empty-string $reason
     *
     * @throws \InvalidArgumentException
     */
    public static function because(string $reason, ?\Throwable $previous = null): self
    {
        $reason = \trim($reason);

        if ($reason === '') {
            throw new \InvalidArgumentException('Report generation failure reason MUST NOT be empty.');
        }

        return new self(\sprintf(
            'The reporter did not generate output because %s',
            \preg_match('/[.!?]\z/D', $reason) === 1 ? $reason : $reason . '.',
        ), $previous);
    }

    /** @internal */
    public static function writeFailed(?\Throwable $previous = null): self
    {
        return new self('Greenlight did not write reporter output to the stream.', $previous);
    }

    /**
     * @internal
     *
     * @param class-string $eventClass
     */
    public static function unmappedEvent(string $eventClass): self
    {
        return new self(\sprintf('Event "%s" has no stable tag. Add the event to the tag map before Greenlight writes it.', $eventClass));
    }

    /** @internal */
    public static function xmlUnavailable(): self
    {
        return new self('The XMLWriter extension is required for JUnit output. Enable ext-xmlwriter.');
    }
}

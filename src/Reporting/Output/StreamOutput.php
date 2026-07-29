<?php

declare(strict_types=1);

namespace Greenlight\Reporting\Output;

use Greenlight\Reporting\ReportingError;

/**
 * Writes reporter text to an open stream resource.
 *
 * The caller controls the resource lifecycle. This class does not open or
 * close the stream.
 *
 * @internal
 */
final class StreamOutput implements Output
{
    /**
     * @param resource $stream
     */
    public function __construct(private $stream) {}

    #[\Override]
    public function write(string $text): void
    {
        while ($text !== '') {
            $written = \fwrite($this->stream, $text);

            if ($written === false || $written === 0) {
                throw ReportingError::writeFailed();
            }

            $text = \substr($text, $written);
        }
    }
}

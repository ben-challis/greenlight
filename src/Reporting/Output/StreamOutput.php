<?php

declare(strict_types=1);

namespace Greenlight\Reporting\Output;

use Greenlight\Reporting\ReportingError;

/**
 * Writes reporter text to an already-open stream resource.
 *
 * The caller owns the resource lifecycle; this class never opens or closes
 * the stream.
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
        if (\fwrite($this->stream, $text) === false) {
            throw ReportingError::writeFailed();
        }
    }
}

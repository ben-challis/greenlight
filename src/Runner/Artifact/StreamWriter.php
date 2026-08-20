<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

use Greenlight\Attribute\CoverageIgnore;
use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\ErrorTrap;

/**
 * Writes complete byte strings to streams.
 *
 * @internal
 */
final class StreamWriter
{
    /**
     * @param resource $stream
     * @throws AttachmentError
     */
    public static function writeFully($stream, string $bytes): void
    {
        $offset = 0;
        $length = \strlen($bytes);

        while ($offset < $length) {
            $written = ErrorTrap::run(static fn() => \fwrite($stream, \substr($bytes, $offset)));

            if ($written === false || $written === 0) {
                throw AttachmentError::storage('Failed to write the complete attachment');
            }

            $offset += $written;
        }
    }

    #[CoverageIgnore]
    private function __construct() {}
}

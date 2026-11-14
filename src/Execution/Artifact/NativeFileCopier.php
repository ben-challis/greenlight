<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Moves a staged file with `rename()` when the source and destination are on
 * the same filesystem. Otherwise, it copies the file and calculates the
 * digest during the copy.
 *
 * @internal
 */
final readonly class NativeFileCopier implements FileCopier
{
    private const int COPY_CHUNK_BYTES = 1024 * 1024;

    /**
     * @throws AttachmentError
     */
    #[\Override]
    public function copy(string $sourcePath, string $destinationPath): string
    {
        if (ErrorTrap::run(static fn() => \rename($sourcePath, $destinationPath))) {
            $digest = ErrorTrap::run(static fn() => \hash_file('sha256', $destinationPath));

            if (!\is_string($digest) || $digest === '') {
                throw AttachmentError::storage('Failed to read the published attachment');
            }

            return $digest;
        }

        $source = ErrorTrap::run(static fn() => \fopen($sourcePath, 'rb'));

        if ($source === false) {
            throw AttachmentError::storage('Failed to copy attachment into its output directory');
        }

        try {
            $destination = ErrorTrap::run(static fn() => \fopen($destinationPath, 'xb'));

            if ($destination === false) {
                throw AttachmentError::storage('Failed to copy attachment into its output directory');
            }

            $hash = \hash_init('sha256');

            try {
                while (!\feof($source)) {
                    $chunk = \fread($source, self::COPY_CHUNK_BYTES);

                    if ($chunk === false) {
                        throw AttachmentError::storage('Failed to read attachment staging content');
                    }

                    if ($chunk !== '') {
                        \hash_update($hash, $chunk);
                        StreamWriter::writeFully($destination, $chunk);
                    }
                }

                if (!\fflush($destination)) {
                    throw AttachmentError::storage('Failed to flush the published attachment');
                }
            } finally {
                \fclose($destination);
            }

            return \hash_final($hash);
        } finally {
            \fclose($source);
        }
    }
}

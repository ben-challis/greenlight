<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Core\ErrorTrap;

/** @internal */
final readonly class NativeFileCopier implements FileCopier
{
    private const int COPY_CHUNK_BYTES = 1024 * 1024;

    /**
     * @throws AttachmentError
     */
    #[\Override]
    public function copy(string $sourcePath, string $destinationPath): void
    {
        $source = ErrorTrap::run(static fn() => \fopen($sourcePath, 'rb'));

        if ($source === false) {
            throw AttachmentError::storage('Failed to copy attachment into its output directory');
        }

        try {
            $destination = ErrorTrap::run(static fn() => \fopen($destinationPath, 'xb'));

            if ($destination === false) {
                throw AttachmentError::storage('Failed to copy attachment into its output directory');
            }

            try {
                while (!\feof($source)) {
                    $chunk = \fread($source, self::COPY_CHUNK_BYTES);

                    if ($chunk === false) {
                        throw AttachmentError::storage('Failed to read attachment staging content');
                    }

                    if ($chunk !== '') {
                        StreamWriter::writeFully($destination, $chunk);
                    }
                }

                if (!\fflush($destination)) {
                    throw AttachmentError::storage('Failed to flush the published attachment');
                }
            } finally {
                \fclose($destination);
            }
        } finally {
            \fclose($source);
        }
    }
}

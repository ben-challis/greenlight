<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

use Greenlight\Core\Artifact\AttachmentError;

/** @internal */
final readonly class NativeFileCopier implements FileCopier
{
    private const int COPY_CHUNK_BYTES = 1024 * 1024;

    #[\Override]
    public function copy(string $sourcePath, string $destinationPath): void
    {
        $source = \fopen($sourcePath, 'rb');
        $destination = \fopen($destinationPath, 'xb');

        if ($source === false || $destination === false) {
            if (\is_resource($source)) {
                \fclose($source);
            }

            if (\is_resource($destination)) {
                \fclose($destination);
            }

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
            \fclose($source);
        }
    }
}

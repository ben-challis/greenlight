<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Internal\Php\ErrorTrap;

/**
 * Links a staged regular file with `link()` when the source and destination
 * are on the same device. The staged file stays until publication succeeds,
 * so crash recovery can still read it. Otherwise, or when the filesystem
 * rejects the link, the copier copies the content into a new regular file and
 * calculates the digest during the copy.
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
        if ($this->linkable($sourcePath, $destinationPath)
            && ErrorTrap::run(static fn() => \link($sourcePath, $destinationPath))
        ) {
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

    /**
     * Tells whether `link()` can publish the source without a copy. The
     * source must be a regular file, not a symbolic link, on the same device
     * as the destination directory.
     */
    private function linkable(string $sourcePath, string $destinationPath): bool
    {
        $source = ErrorTrap::run(static fn() => \lstat($sourcePath));
        $directory = ErrorTrap::run(static fn() => \stat(\dirname($destinationPath)));

        return \is_array($source)
            && \is_array($directory)
            && ($source['mode'] & 0170000) === 0100000
            && $source['dev'] === $directory['dev'];
    }
}

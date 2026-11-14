<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;

/**
 * Puts staged attachment files into their output directory.
 *
 * @internal
 */
interface FileCopier
{
    /**
     * Puts the source content at the destination path. The implementation can
     * create a hard link instead of a copy. The source stays in place.
     *
     * @return non-empty-string The SHA-256 digest of the destination content.
     *   A copy calculates the digest from the bytes that it writes.
     * @throws AttachmentError when the file cannot be copied
     */
    public function copy(string $sourcePath, string $destinationPath): string;
}

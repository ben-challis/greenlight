<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

use Greenlight\Core\Artifact\AttachmentError;

/**
 * Copies attachment files into their output directory.
 *
 * @internal
 */
interface FileCopier
{
    /**
     * @throws AttachmentError when the file cannot be copied
     */
    public function copy(string $sourcePath, string $destinationPath): void;
}

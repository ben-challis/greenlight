<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;

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

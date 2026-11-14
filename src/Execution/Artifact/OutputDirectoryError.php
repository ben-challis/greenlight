<?php

declare(strict_types=1);

namespace Greenlight\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Internal\Filesystem\DirectoryWalkError;
use Greenlight\Internal\Filesystem\DirectoryWalkFailure;

/**
 * Translates a failed output directory walk into an attachment error.
 *
 * @internal
 */
final class OutputDirectoryError
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    public static function from(DirectoryWalkError $error): AttachmentError
    {
        return match ($error->failure) {
            DirectoryWalkFailure::SymbolicLink => AttachmentError::storage('Attachment output directory contains a symbolic link'),
            DirectoryWalkFailure::NotADirectory => AttachmentError::storage('Attachment output path contains a non-directory entry'),
            DirectoryWalkFailure::NoExistingAncestor => AttachmentError::storage('Failed to find the artifact output parent'),
            DirectoryWalkFailure::CreationFailed => AttachmentError::storage(
                'Failed to create attachment output directory' . ($error->warning === null ? '' : ': ' . $error->warning),
            ),
        };
    }
}

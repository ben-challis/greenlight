<?php

declare(strict_types=1);

namespace Greenlight\Internal\Filesystem;

/**
 * Identifies why a symbolic-link-safe directory walk stopped.
 *
 * @internal
 */
enum DirectoryWalkFailure
{
    case SymbolicLink;
    case NotADirectory;
    case NoExistingAncestor;
    case CreationFailed;
}

<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

/**
 * Renames attachment files atomically.
 *
 * @internal
 */
interface FileRenamer
{
    public function rename(string $sourcePath, string $destinationPath): bool;
}

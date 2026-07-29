<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

/**
 * Copies attachment files into their output directory.
 *
 * @internal
 */
interface FileCopier
{
    public function copy(string $sourcePath, string $destinationPath): void;
}

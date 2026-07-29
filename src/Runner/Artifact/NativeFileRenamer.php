<?php

declare(strict_types=1);

namespace Greenlight\Runner\Artifact;

/** @internal */
final readonly class NativeFileRenamer implements FileRenamer
{
    #[\Override]
    public function rename(string $sourcePath, string $destinationPath): bool
    {
        return \rename($sourcePath, $destinationPath);
    }
}

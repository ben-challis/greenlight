<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Artifact;

use Greenlight\Artifact\AttachmentError;
use Greenlight\Doubles\Fake;
use Greenlight\Execution\Artifact\FileCopier;

final class DirectoryCreatingFileCopier implements Fake, FileCopier
{
    public ?string $destination = null;

    #[\Override]
    public function copy(string $sourcePath, string $destinationPath): never
    {
        $this->destination = $destinationPath;

        if (!\mkdir($destinationPath, 0o700, true)) {
            throw new \RuntimeException('The fake copier did not create its destination directory.');
        }

        throw AttachmentError::storage('The fake copier stopped after it created a directory');
    }
}

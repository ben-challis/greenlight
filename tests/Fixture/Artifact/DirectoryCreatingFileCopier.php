<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Artifact;

use Greenlight\Core\Artifact\AttachmentError;
use Greenlight\Doubles\Fake;
use Greenlight\Runner\Artifact\FileCopier;

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

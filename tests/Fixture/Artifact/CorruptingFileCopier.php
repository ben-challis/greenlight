<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Artifact;

use Greenlight\Doubles\Fake;
use Greenlight\Runner\Artifact\FileCopier;

final readonly class CorruptingFileCopier implements Fake, FileCopier
{
    #[\Override]
    public function copy(string $sourcePath, string $destinationPath): void
    {
        $contents = \file_get_contents($sourcePath);

        if (!\is_string($contents) || $contents === '') {
            throw new \RuntimeException('The corrupting file copier did not read the source.');
        }

        $contents[0] = $contents[0] === "\0" ? "\1" : "\0";

        if (\file_put_contents($destinationPath, $contents, \LOCK_EX) !== \strlen($contents)) {
            throw new \RuntimeException('The corrupting file copier did not write the destination.');
        }
    }
}

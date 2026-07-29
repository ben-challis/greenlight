<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Artifact;

use Greenlight\Doubles\Fake;
use Greenlight\Runner\Artifact\FileRenamer;

final class ControlledFileRenamer implements Fake, FileRenamer
{
    public bool $failNext = false;

    /** @var list<array{string, string}> */
    public array $calls = [];

    #[\Override]
    public function rename(string $sourcePath, string $destinationPath): bool
    {
        $this->calls[] = [$sourcePath, $destinationPath];

        if ($this->failNext) {
            $this->failNext = false;

            return false;
        }

        return \rename($sourcePath, $destinationPath);
    }
}

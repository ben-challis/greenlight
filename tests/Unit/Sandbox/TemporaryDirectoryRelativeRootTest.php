<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Sandbox;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;

final readonly class TemporaryDirectoryRelativeRootTest
{
    public function __construct(private TemporaryDirectory $workspace) {}

    #[Test]
    public function disposalFindsANewRelativeRootAfterTheWorkingDirectoryChanges(): void
    {
        $originalDirectory = \getcwd();
        Expect::value($originalDirectory)->toBeString();
        $firstDirectory = $this->workspace->subdirectory('first');
        $secondDirectory = $this->workspace->subdirectory('second');

        try {
            \chdir($firstDirectory);
            $directory = new TemporaryDirectory('new-root');
            $path = $directory->path();
            $absolutePath = \realpath($path);
            Expect::value($absolutePath)->toBeString();
            \file_put_contents($path . '/sentinel.txt', 'temporary data');

            \chdir($secondDirectory);
            $directory->dispose();

            Expect::value(\file_exists($absolutePath))->toBeFalse();
            Expect::value($path)->toBe($absolutePath);
        } finally {
            \chdir($originalDirectory);
        }
    }
}

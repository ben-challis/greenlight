<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

final readonly class StatChangeDetectorTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function ignoresMissingDirectoriesAndNonPhpFiles(): void
    {
        $root = $this->tempDirectory->subdirectory('watch');
        $directory = $root . '/.';
        $textFile = $directory . '/notes.txt';
        \file_put_contents($textFile, 'first');

        $detector = new StatChangeDetector([
            $root . '/missing',
            $directory,
        ]);

        Expect::that($detector->poll())
            ->because('the initial scan records only existing PHP files')
            ->toBe([]);

        \file_put_contents($textFile, 'changed');

        Expect::that($detector->poll())
            ->because('missing directories and non-PHP changes MUST be ignored')
            ->toBe([]);
    }
}

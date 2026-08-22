<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Expect\Expect;
use Greenlight\Internal\Php\ErrorTrap;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\FilesystemRestriction;

final readonly class StatChangeDetectorTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

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

    #[Test]
    #[Isolated]
    public function aRestrictedDirectoryIsIgnoredWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 3);
        $directory = \dirname($root);
        FilesystemRestriction::toProject($root);

        $detector = new StatChangeDetector([$directory]);
        $changed = ErrorTrap::run(static fn() => $detector->poll(), $warning);

        Expect::that($changed)
            ->because('a restricted watch directory MUST behave as a missing directory')
            ->toBe([]);
        Expect::that($warning)
            ->because('a restricted watch directory MUST not leak engine diagnostics')
            ->toBeNull();
    }
}

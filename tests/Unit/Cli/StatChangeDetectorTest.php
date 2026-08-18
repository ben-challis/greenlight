<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Cli;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Cli\Watch\StatChangeDetector;
use Greenlight\Core\ErrorTrap;
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

    #[Test]
    #[Isolated]
    public function aRestrictedDirectoryIsIgnoredWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 3);
        $directory = \dirname($root);
        $previousOpenBasedir = \ini_set('open_basedir', $root . \PATH_SEPARATOR . \sys_get_temp_dir());

        Expect::that($previousOpenBasedir)
            ->because('the isolated fixture MUST restrict access to the watch directory')
            ->not()
            ->toBeFalse();

        $detector = new StatChangeDetector([$directory]);
        $changed = ErrorTrap::run(static fn(): array => $detector->poll(), $warning);

        Expect::that($changed)
            ->because('a restricted watch directory MUST behave as a missing directory')
            ->toBe([]);
        Expect::that($warning)
            ->because('a restricted watch directory MUST not leak engine diagnostics')
            ->toBeNull();
    }
}

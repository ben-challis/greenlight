<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Core\Test\Cleanup;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;

final readonly class DoublesWorkingDirectoryTest
{
    public function __construct(
        private TempDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function missingWorkingDirectoryGivesExactGuidance(): void
    {
        $original = \getcwd();

        Expect::that($original)
            ->because('The test process MUST start in a working directory.')
            ->toBeString();

        $deleted = $this->tempDirectory->subdirectory('deleted-working-directory');
        $this->cleanup->defer(static fn(): bool => \chdir($original));

        Expect::that(\chdir($deleted))
            ->because('the test enters its temporary directory')
            ->toBeTrue();
        Expect::that(\rmdir($deleted))
            ->because('the current temporary directory can be removed')
            ->toBeTrue();
        Expect::that(static fn(): Doubles => new Doubles())
            ->because('the default proxy directory needs a current working directory')
            ->toThrow(
                DoublesError::class,
                message: 'Doubles could not resolve the working directory. Pass a proxy directory explicitly.',
            );
    }
}

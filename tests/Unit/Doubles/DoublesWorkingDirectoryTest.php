<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\InvalidDoubleUsage;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Test\Cleanup;

final readonly class DoublesWorkingDirectoryTest
{
    public function __construct(
        private TemporaryDirectory $tempDirectory,
        private Cleanup $cleanup,
    ) {}

    #[Test]
    public function missingWorkingDirectoryGivesExactGuidance(): void
    {
        $original = \getcwd();

        Expect::value($original)
            ->because('The test process MUST start in a working directory.')
            ->toBeString();

        $deleted = $this->tempDirectory->subdirectory('deleted-working-directory');
        $this->cleanup->defer(static fn(): bool => \chdir($original));

        Expect::value(\chdir($deleted))
            ->because('the test enters its temporary directory')
            ->toBeTrue();
        Expect::value(\rmdir($deleted))
            ->because('the current temporary directory can be removed')
            ->toBeTrue();
        Expect::calling(static fn(): Doubles => new Doubles())
            ->because('the default proxy directory needs a current working directory')
            ->toThrow(
                InvalidDoubleUsage::class,
                message: 'Doubles could not resolve the working directory. Pass a proxy directory explicitly.',
            );
    }
}

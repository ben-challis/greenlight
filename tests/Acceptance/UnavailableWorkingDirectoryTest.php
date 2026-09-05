<?php

declare(strict_types=1);

namespace Greenlight\Tests\Acceptance;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\PhpSubprocess;

final readonly class UnavailableWorkingDirectoryTest
{
    public function __construct(private TemporaryDirectory $directory) {}

    #[Test]
    public function aDeletedWorkingDirectoryReturnsFailure(): void
    {
        $root = \dirname(__DIR__, 2);
        $result = PhpSubprocess::run($root, [
            '-r',
            <<<'PHP'
            if (!chdir($argv[1]) || !rmdir($argv[1])) {
                exit(2);
            }

            require $argv[2];
            PHP,
            $this->directory->subdirectory('removed'),
            $root . '/bin/greenlight',
        ]);

        Expect::that($result->exitCode)->toBe(1);
        Expect::that($result->stderr)->toBe('Could not determine the current working directory.');
        Expect::that($result->stdout)->toBe('');
    }
}

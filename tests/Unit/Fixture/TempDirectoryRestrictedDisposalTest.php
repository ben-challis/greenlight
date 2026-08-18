<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Fixture;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Support\Subprocess;

final readonly class TempDirectoryRestrictedDisposalTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function restrictedRootDisposalFailsWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 3);
        $result = Subprocess::run($root, [
            \PHP_BINARY,
            '-r',
            <<<'PHP'
                require $argv[1];

                $directory = new Greenlight\Fixture\TempDirectory($argv[2]);
                $directory->path();

                if (ini_set('open_basedir', $argv[3]) === false) {
                    exit(22);
                }

                try {
                    Greenlight\Core\ErrorTrap::run(
                        static function () use ($directory): void {
                            $directory->dispose();
                        },
                        $warning,
                    );
                } catch (Greenlight\Fixture\TempDirectoryError $error) {
                    echo $warning === null ? "clean\n" : "leaked: {$warning}\n";
                    echo $error->getMessage();
                    exit(23);
                }

                echo $warning === null ? 'no typed error' : "leaked: {$warning}";
                exit(24);
                PHP,
            $root . '/vendor/autoload.php',
            $this->tempDirectory->path(),
            $root,
        ]);

        Expect::that($result->exitCode)
            ->because('restricted root disposal MUST produce a typed fixture error')
            ->toBe(23);
        Expect::that($result->stdout)
            ->because('restricted root disposal MUST contain diagnostics and identify the failed cleanup')
            ->toStartWith("clean\nFailed to remove temp directory \"")
            ->toContain('open_basedir restriction in effect');
    }
}

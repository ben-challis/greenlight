<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Sandbox;

use Greenlight\Attribute\Test;
use Greenlight\Expect\Expect;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Support\Subprocess;

final readonly class TemporaryDirectoryRestrictedDisposalTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function restrictedRootDisposalFailsWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 3);
        $result = Subprocess::run($root, [
            \PHP_BINARY,
            '-r',
            <<<'PHP'
                require $argv[1];

                $directory = new Greenlight\Sandbox\TemporaryDirectory($argv[2]);
                $directory->path();

                if (ini_set('open_basedir', $argv[3]) === false) {
                    exit(22);
                }

                try {
                    Greenlight\Internal\Php\ErrorTrap::run(
                        static function () use ($directory) {
                            $directory->dispose();
                        },
                        $warning,
                    );
                } catch (Greenlight\Sandbox\TemporaryDirectoryError $error) {
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

<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Core\AtomicFileError;
use Greenlight\Core\ErrorTrap;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Fixture\TempDirectory;
use Greenlight\Tests\Fixture\Doubles\ProxyStorageContract;
use Greenlight\Tests\Support\Subprocess;

final readonly class ProxyStorageTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function aFileThatBlocksTheProxyDirectoryGivesExactGuidance(): void
    {
        $directory = $this->tempDirectory->subdirectory('blocked-proxy-directory') . '/proxies';

        if (\file_put_contents($directory, 'occupied') === false) {
            Fail::because('Expected to create a file at the proxy directory path.');
        }

        $doubles = new Doubles($directory);

        Expect::that(static fn(): object => $doubles->stub(ProxyStorageContract::class))
            ->because('a file that blocks the proxy directory MUST produce a typed storage error')
            ->toThrow(
                DoublesError::class,
                message: 'Doubles could not create the proxy directory '
                    . $directory
                    . ': mkdir(): File exists.',
            );
    }

    #[Test]
    #[Isolated]
    public function aRestrictedProxyDirectoryFailsWithoutEngineDiagnostics(): void
    {
        $root = \dirname(__DIR__, 3);
        $directory = \dirname($root) . '/proxies';
        $previousOpenBasedir = \ini_set('open_basedir', $root . \PATH_SEPARATOR . \sys_get_temp_dir());

        Expect::that($previousOpenBasedir)
            ->because('the isolated fixture MUST restrict access to the proxy directory')
            ->not()
            ->toBeFalse();

        $doubles = new Doubles($directory);
        Expect::that(
            static function () use ($doubles, &$warning): void {
                ErrorTrap::run(
                    static fn(): object => $doubles->stub(ProxyStorageContract::class),
                    $warning,
                );
            },
        )->because('a restricted proxy directory causes a typed storage error')
            ->toThrow(DoublesError::class);
        Expect::that($warning)
            ->because('a restricted proxy directory MUST not leak engine diagnostics')
            ->toBeNull();
    }

    #[Test]
    public function aProxyDirectoryErrorPreservesAZeroStringReason(): void
    {
        Expect::that(DoublesError::proxyDirectoryNotCreated('/tmp/proxies', '0')->getMessage())
            ->because('a proxy-directory diagnostic MUST preserve a zero-string reason')
            ->toBe('Doubles could not create the proxy directory /tmp/proxies: 0.');
    }

    #[Test]
    public function aProxyFileCollisionPreservesTheFileWriteFailure(): void
    {
        $sourceDirectory = $this->tempDirectory->subdirectory('proxy-file-discovery');
        $blockedDirectory = $this->tempDirectory->subdirectory('blocked-proxy-file');

        $file = $blockedDirectory . '/' . $this->generatedProxyFileName($sourceDirectory);

        if (!\mkdir($file)) {
            Fail::because('Expected to create a directory at the generated proxy file path.');
        }

        $doubles = new Doubles($blockedDirectory);

        Expect::that(static fn(): object => $doubles->stub(ProxyStorageContract::class))
            ->because('a directory at the proxy file path MUST produce a typed file error')
            ->toThrow(
                static function (DoublesError $error) use ($file): void {
                    Expect::that($error->getMessage())
                        ->toBe('Doubles could not write the proxy file ' . $file . '.');
                    Expect::that($error->getPrevious())
                        ->because('the proxy error preserves the atomic file failure')
                        ->toBeInstanceOf(AtomicFileError::class);
                },
            );
    }

    /**
     * @return non-empty-string
     */
    private function generatedProxyFileName(string $directory): string
    {
        $root = \dirname(__DIR__, 3);
        $result = Subprocess::run($root, [
            \PHP_BINARY,
            '-r',
            <<<'PHP'
            require $argv[1];

            $doubles = new \Greenlight\Doubles\Doubles($argv[2]);
            $proxy = $doubles->stub(\Greenlight\Tests\Fixture\Doubles\ProxyStorageContract::class);
            $file = new \ReflectionClass($proxy)->getFileName();

            if (!is_string($file)) {
                exit(2);
            }

            echo basename($file);
            PHP,
            $root . '/vendor/autoload.php',
            $directory,
        ]);

        if ($result->exitCode !== 0) {
            Fail::because('Expected the subprocess to generate a proxy file.');
        }

        $file = \trim($result->stdout);

        if ($file === '' || \basename($file) !== $file) {
            Fail::because('Expected the subprocess to report one proxy file name.');
        }

        return $file;
    }
}

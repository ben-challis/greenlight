<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\Test;
use Greenlight\Core\AtomicFileError;
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
    public function aProxyFileCollisionPreservesTheFileWriteFailure(): void
    {
        $sourceDirectory = $this->tempDirectory->subdirectory('proxy-file-discovery');
        $blockedDirectory = $this->tempDirectory->subdirectory('blocked-proxy-file');

        $file = $blockedDirectory . '/' . $this->generatedProxyFileName($sourceDirectory);

        if (!\mkdir($file)) {
            Fail::because('Expected to create a directory at the generated proxy file path.');
        }

        $doubles = new Doubles($blockedDirectory);
        $capture = new class {
            public ?DoublesError $error = null;
        };
        $attempt = static function () use ($capture, $doubles): object {
            try {
                return $doubles->stub(ProxyStorageContract::class);
            } catch (DoublesError $error) {
                $capture->error = $error;

                throw $error;
            }
        };

        Expect::that($attempt)
            ->because('a directory at the proxy file path MUST produce a typed file error')
            ->toThrow(
                DoublesError::class,
                message: 'Doubles could not write the proxy file ' . $file . '.',
            );

        $error = $capture->error;

        if (!$error instanceof DoublesError) {
            Fail::because('Expected proxy generation to throw the captured DoublesError.');
        }

        Expect::that($error->getPrevious())
            ->because('the proxy error preserves the atomic file failure')
            ->toBeInstanceOf(AtomicFileError::class);
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

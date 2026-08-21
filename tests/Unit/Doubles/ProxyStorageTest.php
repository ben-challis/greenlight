<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Doubles;

use Greenlight\Attribute\DataSet;
use Greenlight\Attribute\Isolated;
use Greenlight\Attribute\Test;
use Greenlight\Core\AtomicFileError;
use Greenlight\Core\ErrorTrap;
use Greenlight\Doubles\Doubles;
use Greenlight\Doubles\DoublesError;
use Greenlight\Expect\Expect;
use Greenlight\Expect\Fail;
use Greenlight\Sandbox\TemporaryDirectory;
use Greenlight\Tests\Fixture\Doubles\ProxyStorageContract;
use Greenlight\Tests\Support\FilesystemRestriction;
use Greenlight\Tests\Support\Subprocess;

final readonly class ProxyStorageTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

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
        FilesystemRestriction::toProject($root);

        $doubles = new Doubles($directory);
        Expect::that(
            static function () use ($doubles, &$warning): void {
                ErrorTrap::run(
                    static fn() => $doubles->stub(ProxyStorageContract::class),
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
     * @param class-string<\Throwable>|null $previousType
     */
    #[Test]
    #[DataSet('corruptCachedProxyFiles')]
    public function aCorruptCachedProxyFileFailsWithTypedGuidance(string $source, ?string $previousType): void
    {
        $suffix = \substr(\sha1($source), 0, 8);
        $sourceDirectory = $this->tempDirectory->subdirectory('corrupt-proxy-discovery-' . $suffix);
        $cacheDirectory = $this->tempDirectory->subdirectory('corrupt-proxy-cache-' . $suffix);
        $file = $cacheDirectory . '/' . $this->generatedProxyFileName($sourceDirectory);

        if (\file_put_contents($file, $source) === false) {
            Fail::because('Expected to create a corrupt cached proxy file.');
        }

        $doubles = new Doubles($cacheDirectory);

        Expect::that(static fn(): object => $doubles->stub(ProxyStorageContract::class))
            ->because('a corrupt cached proxy file MUST produce a typed storage error')
            ->toThrow(
                static function (DoublesError $error) use ($file, $previousType): void {
                    Expect::that($error->getMessage())
                        ->toBe('Doubles could not load the proxy file ' . $file . '. Delete the file and retry.');

                    if ($previousType === null) {
                        Expect::that($error->getPrevious())->toBeNull();

                        return;
                    }

                    Expect::that($error->getPrevious())->toBeInstanceOf($previousType);
                },
            );
    }

    /**
     * @return iterable<string, array{non-empty-string, class-string<\Throwable>|null}>
     */
    public static function corruptCachedProxyFiles(): iterable
    {
        yield 'missing generated class' => ["<?php\n", null];
        yield 'invalid PHP syntax' => ['<?php class', \ParseError::class];
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

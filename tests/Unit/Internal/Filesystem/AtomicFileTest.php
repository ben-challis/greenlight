<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Internal\Filesystem;

use Greenlight\Attribute\Test;
use Greenlight\Doubles\Fake;
use Greenlight\Internal\Filesystem\AtomicFile;
use Greenlight\Internal\Filesystem\AtomicFileError;
use Greenlight\Sandbox\TemporaryDirectory;
use Random\Engine;
use Random\RandomException;
use Random\Randomizer;

use function Greenlight\expect;

final readonly class AtomicFileTest
{
    public function __construct(private TemporaryDirectory $tempDirectory) {}

    #[Test]
    public function writeReplacesTheTargetWithoutTemporaryResidue(): void
    {
        $directory = $this->tempDirectory->subdirectory('replacement');
        $path = $directory . '/state.bin';
        \file_put_contents($path, 'old');

        AtomicFile::write($path, "\x00new\n");

        expect(\file_get_contents($path))
            ->because('an atomic write replaces the target with the exact bytes')
            ->toBe("\x00new\n");
        expect(\glob($path . '.tmp-*'))
            ->because('a successful atomic write leaves no temporary file')
            ->toBe([]);
    }

    #[Test]
    public function temporaryWriteFailureThrowsADiagnostic(): void
    {
        $path = $this->tempDirectory->path() . '/missing/state.json';

        expect()->calling(static fn() => AtomicFile::write($path, '{}'))
            ->because('a failed temporary write identifies its generated path')
            ->toThrow(
                AtomicFileError::class,
                matching: '/^Cannot write temporary file ".*\/state\.json\.tmp-\d+-[0-9a-f]{16}": .+\.$/',
            );

        expect(\glob($path . '.tmp-*'))
            ->because('a failed temporary write leaves no temporary file')
            ->toBe([]);
    }

    #[Test]
    public function temporaryWriteThrowableBecomesAnAtomicFileError(): void
    {
        $path = $this->tempDirectory->path() . "/invalid\0/state.json";

        expect()->calling(static fn() => AtomicFile::write($path, '{}'))
            ->because('a native write throwable MUST not escape the atomic-file seam')
            ->toThrow(
                static function (AtomicFileError $error): void {
                    expect($error->getPrevious())
                        ->because('the atomic-file error MUST preserve the native write error')
                        ->toBeInstanceOf(\ValueError::class);
                },
            );
    }

    #[Test]
    public function randomNameFailurePreservesItsCauseWithoutWritingAFile(): void
    {
        $path = $this->tempDirectory->path() . '/state.json';
        $cause = new RandomException('entropy unavailable');
        $randomizer = new Randomizer(new readonly class ($cause) implements Engine, Fake {
            public function __construct(private RandomException $cause) {}

            #[\Override]
            public function generate(): never
            {
                throw $this->cause;
            }
        });
        expect()->calling(static fn() => AtomicFile::write($path, 'content', $randomizer))
            ->because('the temporary-name failure MUST identify its target and cause')
            ->toThrow(
                static function (AtomicFileError $error) use ($cause, $path): void {
                    expect($error->getMessage())->toBe(\sprintf(
                        'Cannot generate a temporary name for "%s": entropy unavailable',
                        $path,
                    ));
                    expect($error->getPrevious())
                        ->because('the temporary-name failure MUST preserve its original cause')
                        ->toBe($cause);
                },
            );

        expect(\file_exists($path))
            ->because('an entropy failure MUST NOT create the target file')
            ->toBeFalse();
    }

    #[Test]
    public function renameFailureThrowsADiagnosticAndRemovesTheTemporaryFile(): void
    {
        $path = $this->tempDirectory->subdirectory('rename-target');

        expect()->calling(static fn() => AtomicFile::write($path, 'content'))
            ->because('a failed rename identifies the temporary and target paths')
            ->toThrow(
                AtomicFileError::class,
                matching: '/^Cannot rename ".*\/rename-target\.tmp-\d+-[0-9a-f]{16}" to ".*\/rename-target": .+\.$/',
            );

        expect(\glob($path . '.tmp-*'))
            ->because('a failed rename removes the temporary file')
            ->toBe([]);
    }

    #[Test]
    public function errorFactoriesPreserveTheirExactDiagnostics(): void
    {
        $previous = new \RuntimeException('entropy unavailable');
        $name = AtomicFileError::cannotNameTemporary('/state.json', $previous);
        $write = AtomicFileError::cannotWriteTemporary('/state.json.tmp-1-abcd', 'disk full');
        $writeWithoutReason = AtomicFileError::cannotWriteTemporary('/state.json.tmp-1-abcd', null);
        $rename = AtomicFileError::cannotRename('/state.json.tmp-1-abcd', '/state.json', 'permission denied');
        $renameWithoutReason = AtomicFileError::cannotRename('/state.json.tmp-1-abcd', '/state.json', null);

        expect($name->getMessage())
            ->because('the random-name diagnostic includes the target and original message')
            ->toBe('Cannot generate a temporary name for "/state.json": entropy unavailable');
        expect($name->getPrevious())
            ->because('the random-name diagnostic preserves the original error')
            ->toBe($previous);
        expect($write->getMessage())
            ->because('the temporary-write diagnostic includes its warning')
            ->toBe('Cannot write temporary file "/state.json.tmp-1-abcd": disk full.');
        expect($writeWithoutReason->getMessage())
            ->because('the temporary-write diagnostic omits punctuation for a missing warning')
            ->toBe('Cannot write temporary file "/state.json.tmp-1-abcd".');
        expect($rename->getMessage())
            ->because('the rename diagnostic includes both paths and its warning')
            ->toBe('Cannot rename "/state.json.tmp-1-abcd" to "/state.json": permission denied.');
        expect($renameWithoutReason->getMessage())
            ->because('the rename diagnostic omits punctuation for a missing warning')
            ->toBe('Cannot rename "/state.json.tmp-1-abcd" to "/state.json".');
    }

    #[Test]
    public function temporaryWriteErrorsPreserveZeroStringWarnings(): void
    {
        $write = AtomicFileError::cannotWriteTemporary('/state.json.tmp-1-abcd', '0');

        expect($write->getMessage())
            ->because('the temporary-write diagnostic MUST preserve a zero-string warning')
            ->toBe('Cannot write temporary file "/state.json.tmp-1-abcd": 0.');
    }

    #[Test]
    public function renameErrorsPreserveZeroStringWarnings(): void
    {
        $rename = AtomicFileError::cannotRename('/state.json.tmp-1-abcd', '/state.json', '0');

        expect($rename->getMessage())
            ->because('the rename diagnostic MUST preserve a zero-string warning')
            ->toBe('Cannot rename "/state.json.tmp-1-abcd" to "/state.json": 0.');
    }
}

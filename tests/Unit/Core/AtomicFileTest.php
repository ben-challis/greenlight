<?php

declare(strict_types=1);

namespace Greenlight\Tests\Unit\Core;

use Greenlight\Attribute\Test;
use Greenlight\Core\AtomicFile;
use Greenlight\Core\AtomicFileError;
use Greenlight\Doubles\Fake;
use Greenlight\Expect\Expect;
use Greenlight\Fixture\TempDirectory;
use Random\Engine;
use Random\RandomException;
use Random\Randomizer;

final readonly class AtomicFileTest
{
    public function __construct(private TempDirectory $tempDirectory) {}

    #[Test]
    public function writeReplacesTheTargetWithoutTemporaryResidue(): void
    {
        $directory = $this->tempDirectory->subdirectory('replacement');
        $path = $directory . '/state.bin';
        \file_put_contents($path, 'old');

        AtomicFile::write($path, "\x00new\n");

        Expect::that(\file_get_contents($path))
            ->because('an atomic write replaces the target with the exact bytes')
            ->toBe("\x00new\n")
            ->and(\glob($path . '.tmp-*'))
            ->because('a successful atomic write leaves no temporary file')
            ->toBe([]);
    }

    #[Test]
    public function temporaryWriteFailureThrowsADiagnostic(): void
    {
        $path = $this->tempDirectory->path() . '/missing/state.json';

        Expect::that(static fn() => AtomicFile::write($path, '{}'))
            ->because('a failed temporary write identifies its generated path')
            ->toThrow(
                AtomicFileError::class,
                matching: '/^Cannot write temporary file ".*\/state\.json\.tmp-\d+-[0-9a-f]{16}": .+\.$/',
            );

        Expect::that(\glob($path . '.tmp-*'))
            ->because('a failed temporary write leaves no temporary file')
            ->toBe([]);
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
        $capture = new class implements Fake {
            public ?AtomicFileError $error = null;
        };

        Expect::that(static function () use ($path, $randomizer, $capture): void {
            try {
                AtomicFile::write($path, 'content', $randomizer);
            } catch (AtomicFileError $error) {
                $capture->error = $error;

                throw $error;
            }
        })
            ->because('the temporary-name failure MUST identify its target and cause')
            ->toThrow(
                AtomicFileError::class,
                message: \sprintf(
                    'Cannot generate a temporary name for "%s": entropy unavailable',
                    $path,
                ),
            );

        Expect::that($capture->error?->getPrevious())
            ->because('the temporary-name failure MUST preserve its original cause')
            ->toBe($cause)
            ->and(\file_exists($path))
            ->because('an entropy failure MUST NOT create the target file')
            ->toBeFalse();
    }

    #[Test]
    public function renameFailureThrowsADiagnosticAndRemovesTheTemporaryFile(): void
    {
        $path = $this->tempDirectory->subdirectory('rename-target');

        Expect::that(static fn() => AtomicFile::write($path, 'content'))
            ->because('a failed rename identifies the temporary and target paths')
            ->toThrow(
                AtomicFileError::class,
                matching: '/^Cannot rename ".*\/rename-target\.tmp-\d+-[0-9a-f]{16}" to ".*\/rename-target": .+\.$/',
            );

        Expect::that(\glob($path . '.tmp-*'))
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

        Expect::that($name->getMessage())
            ->because('the random-name diagnostic includes the target and original message')
            ->toBe('Cannot generate a temporary name for "/state.json": entropy unavailable')
            ->and($name->getPrevious())
            ->because('the random-name diagnostic preserves the original error')
            ->toBe($previous)
            ->and($write->getMessage())
            ->because('the temporary-write diagnostic includes its warning')
            ->toBe('Cannot write temporary file "/state.json.tmp-1-abcd": disk full.')
            ->and($writeWithoutReason->getMessage())
            ->because('the temporary-write diagnostic omits punctuation for a missing warning')
            ->toBe('Cannot write temporary file "/state.json.tmp-1-abcd".')
            ->and($rename->getMessage())
            ->because('the rename diagnostic includes both paths and its warning')
            ->toBe('Cannot rename "/state.json.tmp-1-abcd" to "/state.json": permission denied.')
            ->and($renameWithoutReason->getMessage())
            ->because('the rename diagnostic omits punctuation for a missing warning')
            ->toBe('Cannot rename "/state.json.tmp-1-abcd" to "/state.json".');
    }
}

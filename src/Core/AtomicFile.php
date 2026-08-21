<?php

declare(strict_types=1);

namespace Greenlight\Core;

use Random\RandomException;
use Random\Randomizer;

/**
 * Writes shared state files as atomic operations.
 *
 * write() puts the content in a temporary file with a unique name in the
 * target directory. It then renames the file to the target name. Thus,
 * concurrent processes cannot combine partial writes to the same file.
 * A failure removes the temporary file and throws AtomicFileError with the
 * applicable warning. A caller can catch this error if the write is advisory.
 *
 * @internal
 */
final class AtomicFile
{
    /** @codeCoverageIgnore */
    private function __construct() {}

    /**
     * @throws AtomicFileError
     */
    public static function write(string $path, string $contents, ?Randomizer $randomizer = null): void
    {
        try {
            $suffix = \bin2hex(($randomizer ?? new Randomizer())->getBytes(8));
        } catch (RandomException $exception) {
            throw AtomicFileError::cannotNameTemporary($path, $exception);
        }

        $temp = \sprintf('%s.tmp-%s-%s', $path, (int) \getmypid(), $suffix);

        $written = ErrorTrap::run(
            static fn() => \file_put_contents($temp, $contents),
            $warning,
            wrap: static fn(\Throwable $error): AtomicFileError =>
                AtomicFileError::cannotWriteTemporary($temp, $error->getMessage(), $error),
        );

        if ($written === false) {
            ErrorTrap::run(static fn() => \unlink($temp));

            throw AtomicFileError::cannotWriteTemporary($temp, $warning);
        }

        $renamed = ErrorTrap::run(
            static fn() => \rename($temp, $path),
            $warning,
            wrap: static fn(\Throwable $error): AtomicFileError =>
                AtomicFileError::cannotRename($temp, $path, $error->getMessage(), $error),
        );

        if ($renamed === false) {
            ErrorTrap::run(static fn() => \unlink($temp));

            throw AtomicFileError::cannotRename($temp, $path, $warning);
        }
    }
}

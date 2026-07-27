<?php

declare(strict_types=1);

namespace Greenlight\Core;

use Random\RandomException;

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
    public static function write(string $path, string $contents): void
    {
        try {
            $suffix = \bin2hex(\random_bytes(8));
        } catch (RandomException $exception) {
            throw AtomicFileError::cannotNameTemporary($path, $exception);
        }

        $temp = \sprintf('%s.tmp-%s-%s', $path, (int) \getmypid(), $suffix);

        if (ErrorTrap::run(static fn(): int|false => \file_put_contents($temp, $contents), $warning) === false) {
            ErrorTrap::run(static fn(): bool => \unlink($temp));

            throw AtomicFileError::cannotWriteTemporary($temp, $warning);
        }

        if (ErrorTrap::run(static fn(): bool => \rename($temp, $path), $warning) === false) {
            ErrorTrap::run(static fn(): bool => \unlink($temp));

            throw AtomicFileError::cannotRename($temp, $path, $warning);
        }
    }
}

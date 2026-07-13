<?php

declare(strict_types=1);

namespace Greenlight\Core;

use Random\RandomException;

/**
 * Atomic file writes for state files shared between processes.
 *
 * write() puts the contents in a uniquely named temp file in the target's
 * directory, then renames it over the target, so concurrent writers of the
 * same file cannot interleave partial writes. Failure leaves no temp file
 * behind and throws AtomicFileError carrying the underlying warning; callers
 * for whom the write is advisory catch and discard it.
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

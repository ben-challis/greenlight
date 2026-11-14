<?php

declare(strict_types=1);

namespace Greenlight\Internal\Filesystem;

/**
 * Reports the path and the reason that stopped a directory walk.
 * Callers translate the failure into their own diagnostic.
 *
 * @internal
 */
final class DirectoryWalkError extends \RuntimeException
{
    public function __construct(
        public readonly DirectoryWalkFailure $failure,
        public readonly string $path,
        public readonly ?string $warning = null,
    ) {
        parent::__construct(\sprintf('Directory walk stopped at "%s": %s.', $path, $failure->name));
    }
}

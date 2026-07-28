<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Filesystem;

use Greenlight\Doubles\Fake;

/**
 * Simulates file metadata for paths that the host filesystem cannot create.
 */
final class StatableFileStream implements Fake
{
    public mixed $context;

    /**
     * @return array{mode: int, size: int, mtime: int}
     */
    public function url_stat(string $path, int $flags): array
    {
        return [
            'mode' => 0100600,
            'size' => 1,
            'mtime' => 1,
        ];
    }
}

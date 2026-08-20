<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Filesystem;

use Greenlight\Doubles\Fake;

/** Simulates a directory that disappears after its metadata is read. */
final class VanishingDirectoryStream implements Fake
{
    public mixed $context;

    /** @return array{mode: int} */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0o040700];
    }

    public function dir_opendir(string $path, int $options): false
    {
        return false;
    }
}

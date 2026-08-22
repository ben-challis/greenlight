<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\Artifact;

use Greenlight\Doubles\Fake;

/**
 * Simulates a regular file whose size cannot be determined.
 */
final class UnknownSizeFileStream implements Fake
{
    public mixed $context;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        return $mode === 'rb';
    }

    /**
     * @return array{mode: int, size: int}
     */
    public function stream_stat(): array
    {
        return self::stat();
    }

    /**
     * @return array{mode: int, size: int}
     */
    public function url_stat(string $path, int $flags): array
    {
        return self::stat();
    }

    /**
     * @return array{mode: int, size: int}
     */
    private static function stat(): array
    {
        return [
            'mode' => 0100400,
            'size' => -1,
        ];
    }
}

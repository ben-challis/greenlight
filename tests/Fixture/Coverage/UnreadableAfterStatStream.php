<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Doubles\Fake;

/**
 * Simulates a regular file that becomes unreadable after a successful stat.
 */
final class UnreadableAfterStatStream implements Fake
{
    public mixed $context;

    /**
     * @return array{mode: int}
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100444];
    }

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        return false;
    }
}

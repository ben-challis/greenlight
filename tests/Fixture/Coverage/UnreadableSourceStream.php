<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Coverage;

use Greenlight\Doubles\Fake;

/**
 * Simulates a regular source file without read permission.
 */
final class UnreadableSourceStream implements Fake
{
    public mixed $context;

    public static int $openCalls = 0;

    /**
     * @return array{mode: int}
     */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0100000];
    }

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        ++self::$openCalls;

        return false;
    }
}

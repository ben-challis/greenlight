<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Reporting;

use Greenlight\Doubles\Fake;

/**
 * Accepts at most three bytes from each write, or simulates a stalled stream.
 */
final class PartialWriteStream implements Fake
{
    public mixed $context;

    private static string $contents = '';

    private bool $stalled = false;

    public static function contents(): string
    {
        return self::$contents;
    }

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath,
    ): bool {
        self::$contents = '';
        $this->stalled = \str_ends_with($path, '/stalled');

        return $mode === 'wb';
    }

    public function stream_write(string $data): int
    {
        if ($this->stalled) {
            return 0;
        }

        $chunk = \substr($data, 0, 3);
        self::$contents .= $chunk;

        return \strlen($chunk);
    }
}

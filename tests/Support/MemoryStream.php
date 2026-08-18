<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Expect\Fail;

final class MemoryStream
{
    /**
     * @return resource
     */
    public static function open(string $contents = ''): mixed
    {
        $stream = \fopen('php://memory', 'r+');

        if (!\is_resource($stream)) {
            Fail::because('The in-memory stream did not open.');
        }

        if (\fwrite($stream, $contents) !== \strlen($contents) || !\rewind($stream)) {
            \fclose($stream);

            Fail::because('The in-memory stream did not accept its initial content.');
        }

        return $stream;
    }

    public static function close(mixed ...$streams): void
    {
        foreach ($streams as $stream) {
            if (\is_resource($stream)) {
                \fclose($stream);
            }
        }
    }

    private function __construct() {}
}

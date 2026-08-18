<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Expect\Fail;

final readonly class ConnectedStreamPair
{
    /**
     * @return array{resource, resource}
     */
    public static function open(): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);

        if ($pair === false || \count($pair) !== 2 || !isset($pair[0], $pair[1])) {
            Fail::because('Expected stream_socket_pair() to create a connected stream pair.');
        }

        return [$pair[0], $pair[1]];
    }
}

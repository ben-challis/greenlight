<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Expect\Expect;

final readonly class ConnectedStreamPair
{
    /**
     * @return array{resource, resource}
     */
    public static function open(): array
    {
        $pair = \stream_socket_pair(\STREAM_PF_UNIX, \STREAM_SOCK_STREAM, \STREAM_IPPROTO_IP);

        Expect::that($pair)
            ->because('stream_socket_pair() MUST create an array.')
            ->toBeArray();
        Expect::that($pair)
            ->because('stream_socket_pair() MUST create a pair.')
            ->toHaveCount(2);
        Expect::that(isset($pair[0], $pair[1]))
            ->because('The connected stream pair MUST contain both streams.')
            ->toBeTrue();

        return [$pair[0], $pair[1]];
    }
}

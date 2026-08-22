<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

/** @internal */
final readonly class NativeServerSocketRuntime implements ServerSocketRuntime
{
    #[\Override]
    public function listen(string $address, ?string &$errorMessage)
    {
        $errorCode = 0;

        return \stream_socket_server($address, $errorCode, $errorMessage);
    }

    #[\Override]
    public function name($server): string|false
    {
        return \stream_socket_get_name($server, false);
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Execution\ProcessPool\Orchestrator;

/**
 * Supplies native stream operations to the orchestrator listener.
 *
 * @internal
 */
interface ServerSocketRuntime
{
    /**
     * @param-out string|null $errorMessage
     *
     * @return resource|false
     */
    public function listen(string $address, ?string &$errorMessage);

    /**
     * @param resource $server
     */
    public function name($server): string|false;
}

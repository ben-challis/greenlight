<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Runner\Orchestrator;

use Greenlight\Doubles\Fake;
use Greenlight\Runner\Orchestrator\ServerSocketRuntime;

final class ControlledServerSocketRuntime implements Fake, ServerSocketRuntime
{
    /** @var resource|null */
    private $tcpServer = null;

    private ?string $unixAddress = null;

    public function __construct(
        private readonly bool $tcpOpens,
        private readonly string|false $tcpName = false,
    ) {}

    #[\Override]
    public function listen(string $address, ?string &$errorMessage)
    {
        if (\str_starts_with($address, 'unix://')) {
            $this->unixAddress = $address;
            $errorMessage = 'the fixture rejected the Unix listener';

            return false;
        }

        if (!$this->tcpOpens) {
            $errorMessage = 'the fixture rejected the TCP listener';

            return false;
        }

        $server = \fopen('php://temp', 'r+');

        if (!\is_resource($server)) {
            throw new \RuntimeException('The controlled socket runtime did not create its TCP stream.');
        }

        return $this->tcpServer = $server;
    }

    #[\Override]
    public function name($server): string|false
    {
        return $this->tcpName;
    }

    public function tcpServerIsOpen(): bool
    {
        return \is_resource($this->tcpServer);
    }

    public function unixDirectoryExists(): bool
    {
        return $this->unixAddress !== null
            && \is_dir(\dirname(\substr($this->unixAddress, \strlen('unix://'))));
    }
}

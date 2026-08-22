<?php

declare(strict_types=1);

namespace Greenlight\Tests\Fixture\Execution\ProcessPool\Orchestrator;

use Greenlight\Doubles\Fake;
use Greenlight\Execution\ProcessPool\Orchestrator\ServerSocketRuntime;
use Greenlight\Tests\Support\MemoryStream;

final class TruncatingServerSocketRuntime implements Fake, ServerSocketRuntime
{
    /** @var resource|null */
    private $unixServer = null;

    private ?string $unixAddress = null;

    public function __construct(private readonly string $boundUnixPath) {}

    /** @return resource */
    #[\Override]
    public function listen(string $address, ?string &$errorMessage)
    {
        $server = MemoryStream::open();

        if (\str_starts_with($address, 'unix://')) {
            $this->unixAddress = $address;

            return $this->unixServer = $server;
        }

        return $server;
    }

    #[\Override]
    public function name($server): string
    {
        return $server === $this->unixServer
            ? $this->boundUnixPath
            : '127.0.0.1:12345';
    }

    public function unixServerIsOpen(): bool
    {
        return \is_resource($this->unixServer);
    }

    public function unixDirectoryExists(): bool
    {
        return $this->unixAddress !== null
            && \is_dir(\dirname(\substr($this->unixAddress, \strlen('unix://'))));
    }
}

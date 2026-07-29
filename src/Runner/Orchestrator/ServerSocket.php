<?php

declare(strict_types=1);

namespace Greenlight\Runner\Orchestrator;

use Greenlight\Core\ErrorTrap;
use Greenlight\Runner\Protocol\ProtocolError;

/**
 * Opens the orchestrator listener and owns its cleanup.
 *
 * @internal
 */
final class ServerSocket
{
    /**
     * @param resource $stream
     * @param non-empty-string $address
     */
    private function __construct(
        private $stream,
        public readonly string $address,
        private readonly ?string $unixPath,
    ) {}

    public static function listen(
        ?string $temporaryDirectory = null,
        ?ServerSocketRuntime $runtime = null,
    ): self {
        $temporaryDirectory ??= \sys_get_temp_dir();
        $runtime ??= new NativeServerSocketRuntime();
        $socketPath = \rtrim($temporaryDirectory, '/')
            . '/greenlight-' . \bin2hex(\random_bytes(6)) . '/orchestrator.sock';
        $errorMessage = null;

        $server = ErrorTrap::run(static function () use ($runtime, $socketPath, &$errorMessage) {
            \mkdir(\dirname($socketPath), 0o700, true);

            return $runtime->listen('unix://' . $socketPath, $errorMessage);
        });

        if (\is_resource($server)) {
            return new self($server, 'unix://' . $socketPath, $socketPath);
        }

        ErrorTrap::run(static fn(): bool => \rmdir(\dirname($socketPath)));

        $server = ErrorTrap::run(static function () use ($runtime, &$errorMessage) {
            return $runtime->listen('tcp://127.0.0.1:0', $errorMessage);
        });

        if (!\is_resource($server)) {
            throw ProtocolError::malformedFrame(
                'Greenlight did not open an orchestrator socket: ' . ($errorMessage ?? 'unknown error'),
            );
        }

        $name = $runtime->name($server);

        if ($name === false || $name === '') {
            ErrorTrap::run(static fn(): bool => \fclose($server));

            throw ProtocolError::malformedFrame(
                'Greenlight did not resolve the orchestrator socket address',
            );
        }

        return new self($server, 'tcp://' . $name, null);
    }

    /**
     * @return resource
     */
    public function stream()
    {
        return $this->stream;
    }

    public function close(): void
    {
        ErrorTrap::run(function (): void {
            \fclose($this->stream);

            if ($this->unixPath !== null) {
                \unlink($this->unixPath);
                \rmdir(\dirname($this->unixPath));
            }
        });
    }
}

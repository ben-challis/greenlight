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
    private const int PORTABLE_UNIX_PATH_BYTES = 100;

    /**
     * @param resource $stream
     * @param non-empty-string $address
     */
    private function __construct(
        private $stream,
        public readonly string $address,
        private readonly ?string $unixPath,
    ) {}

    public static function listen(?string $temporaryDirectory = null): self
    {
        $temporaryDirectory ??= \sys_get_temp_dir();
        $socketPath = \rtrim($temporaryDirectory, '/')
            . '/gl-' . \bin2hex(\random_bytes(6)) . '/s';
        $server = false;

        if (\strlen($socketPath) <= self::PORTABLE_UNIX_PATH_BYTES) {
            $server = ErrorTrap::run(static function () use ($socketPath) {
                \mkdir(\dirname($socketPath), 0o700, true);

                return \stream_socket_server('unix://' . $socketPath, $errorCode, $errorMessage);
            });
        }

        if (\is_resource($server)) {
            return new self($server, 'unix://' . $socketPath, $socketPath);
        }

        $server = ErrorTrap::run(static function () use (&$errorCode, &$errorMessage) {
            return \stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        });

        if (!\is_resource($server)) {
            throw ProtocolError::malformedFrame(
                'Greenlight did not open an orchestrator socket: ' . $errorMessage,
            );
        }

        $name = \stream_socket_get_name($server, false);

        if ($name === false || $name === '') {
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

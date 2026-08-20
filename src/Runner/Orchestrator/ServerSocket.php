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

    /**
     * @throws ProtocolError
     */
    public static function listen(
        ?string $temporaryDirectory = null,
        ?ServerSocketRuntime $runtime = null,
    ): self {
        $temporaryDirectory ??= \sys_get_temp_dir();
        $runtime ??= new NativeServerSocketRuntime();
        $socketDirectory = \rtrim($temporaryDirectory, '/')
            . '/gl-' . \bin2hex(\random_bytes(6));
        $socketPath = $socketDirectory . '/s';
        $errorMessage = null;

        $server = ErrorTrap::run(static function () use ($runtime, $socketDirectory, $socketPath, &$errorMessage) {
            \mkdir($socketDirectory, 0o700);

            return $runtime->listen('unix://' . $socketPath, $errorMessage);
        });

        if (\is_resource($server)) {
            $boundPath = $runtime->name($server);

            if ($boundPath === $socketPath) {
                return new self($server, 'unix://' . $socketPath, $socketPath);
            }

            ErrorTrap::run(static function () use ($server, $boundPath, $socketPath) {
                if (\is_string($boundPath)) {
                    \unlink($boundPath);
                }

                \unlink($socketPath);
                \fclose($server);
            });
        }

        ErrorTrap::run(static fn() => \rmdir($socketDirectory));

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
            ErrorTrap::run(static fn() => \fclose($server));

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
        ErrorTrap::run(function () {
            if (\is_resource($this->stream)) {
                \fclose($this->stream);
            }

            if ($this->unixPath !== null) {
                \unlink($this->unixPath);
                \rmdir(\dirname($this->unixPath));
            }
        });
    }
}

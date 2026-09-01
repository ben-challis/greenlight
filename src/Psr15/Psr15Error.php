<?php

declare(strict_types=1);

namespace Greenlight\Psr15;

/** Reports a PSR-15 harness failure at its public seam. */
final class Psr15Error extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function factoryFailed(\Throwable $cause): self
    {
        return new self('The PSR-15 handler factory failed.', previous: $cause);
    }

    public static function invalidHandler(mixed $handler): self
    {
        return new self(\sprintf(
            'The PSR-15 handler factory returned "%s". It MUST return an instance of "Psr\\Http\\Server\\RequestHandlerInterface".',
            \get_debug_type($handler),
        ));
    }

    public static function requestFailed(
        string $method,
        string $path,
        mixed $handler,
        \Throwable $cause,
    ): self {
        return new self(\sprintf(
            'PSR-15 handler "%s" failed for request "%s %s".',
            \get_debug_type($handler),
            $method,
            $path,
        ), previous: $cause);
    }

    public static function releaseFailed(mixed $handler, \Throwable $cause): self
    {
        return new self(\sprintf(
            'The release callback failed for PSR-15 handler "%s".',
            \get_debug_type($handler),
        ), previous: $cause);
    }

    public static function disposed(): self
    {
        return new self('The PSR-15 HTTP harness is closed. Create a new harness for the next request.');
    }
}

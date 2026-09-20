<?php

declare(strict_types=1);

namespace Greenlight\Cli\Watch;

/**
 * A configured watch source cannot complete an operation.
 *
 * @internal
 */
final class WatchSourceFailed extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /** @param class-string $plugin */
    public static function operation(string $plugin, string $operation, \Throwable $cause): self
    {
        return new self(\sprintf(
            'Watch source plugin "%s" caused an error during %s: %s',
            $plugin,
            $operation,
            $cause->getMessage(),
        ), $cause);
    }

    /** @param class-string $plugin */
    public static function invalidChange(string $plugin, mixed $change): self
    {
        return new self(\sprintf(
            'Watch source plugin "%s" returned %s. Return non-empty string paths or labels.',
            $plugin,
            \get_debug_type($change),
        ));
    }
}

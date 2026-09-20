<?php

declare(strict_types=1);

namespace Greenlight\Cli\Plugin;

/**
 * A command plugin did not supply a usable command registry.
 *
 * @internal
 */
final class CommandSetupFailed extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    public static function providerFailed(string $provider, \Throwable $cause): self
    {
        return new self(\sprintf(
            'Command provider "%s" caused an error: %s',
            $provider,
            $cause->getMessage(),
        ), previous: $cause);
    }

    public static function invalidDefinition(string $provider, int $position): self
    {
        return new self(\sprintf(
            'Command provider "%s" returned an invalid command definition at position %d.',
            $provider,
            $position,
        ));
    }

    public static function duplicateName(string $name): self
    {
        return new self(\sprintf('Command name "%s" is registered more than once.', $name));
    }
}

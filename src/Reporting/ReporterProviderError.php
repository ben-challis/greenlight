<?php

declare(strict_types=1);

namespace Greenlight\Reporting;

/**
 * A reporter provider or reporter factory did not supply a valid reporter.
 *
 * @internal
 */
final class ReporterProviderError extends \RuntimeException
{
    private function __construct(string $message, ?\Throwable $previous = null)
    {
        parent::__construct($message, previous: $previous);
    }

    /** @param class-string $provider */
    public static function providerFailed(string $provider, \Throwable $previous): self
    {
        return new self(\sprintf(
            'Reporter provider "%s" failed: %s',
            self::providerName($provider),
            self::sentence($previous->getMessage()),
        ), $previous);
    }

    /** @param class-string $provider */
    public static function invalidDefinition(string $provider, int $position): self
    {
        return new self(\sprintf(
            'Reporter provider "%s" returned an invalid definition at position %d. Return only ReporterDefinition objects.',
            self::providerName($provider),
            $position,
        ));
    }

    public static function duplicateName(string $name): self
    {
        return new self(\sprintf('Reporter name "%s" is registered more than one time.', $name));
    }

    public static function factoryFailed(string $name, \Throwable $previous): self
    {
        return new self(\sprintf(
            'Reporter factory "%s" failed: %s',
            $name,
            self::sentence($previous->getMessage()),
        ), $previous);
    }

    public static function invalidReporter(string $name): self
    {
        return new self(\sprintf('Reporter factory "%s" did not return a Reporter object.', $name));
    }

    private static function sentence(string $message): string
    {
        $message = \trim($message);

        if ($message === '') {
            return 'No error message was available.';
        }

        return \preg_match('/[.!?]\z/D', $message) === 1 ? $message : $message . '.';
    }

    /** @param class-string $provider */
    private static function providerName(string $provider): string
    {
        $separator = \strpos($provider, "\0");

        return $separator === false ? $provider : \substr($provider, 0, $separator);
    }
}

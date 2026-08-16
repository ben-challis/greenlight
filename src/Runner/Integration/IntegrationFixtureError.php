<?php

declare(strict_types=1);

namespace Greenlight\Runner\Integration;

/**
 * A run-level integration fixture provisioning or teardown failure.
 *
 * @internal
 */
final class IntegrationFixtureError extends \RuntimeException
{
    /**
     * @param class-string $provider
     */
    public static function provider(string $provider, \Throwable $failure): self
    {
        return new self(\sprintf(
            'Integration fixture provider "%s" failed: %s',
            $provider,
            self::sentence($failure->getMessage()),
        ), 0, $failure);
    }

    /**
     * @param list<array{non-empty-string, \Throwable}> $cleanupFailures
     */
    public static function provisioning(string $fixture, \Throwable $failure, array $cleanupFailures): self
    {
        $message = \sprintf(
            'Integration fixture "%s" failed to provision: %s',
            $fixture,
            self::sentence($failure->getMessage()),
        );

        return new self(self::appendCleanupFailures($message, $cleanupFailures), 0, $failure);
    }

    /**
     * @param list<array{non-empty-string, \Throwable}> $cleanupFailures
     */
    public static function cleanup(array $cleanupFailures): self
    {
        return new self(self::appendCleanupFailures('Integration fixture teardown failed.', $cleanupFailures));
    }

    /**
     * @param list<array{non-empty-string, \Throwable}> $cleanupFailures
     */
    public static function afterFailure(\Throwable $failure, array $cleanupFailures): self
    {
        $message = \sprintf('The run failed with %s: %s', $failure::class, self::sentence($failure->getMessage()));

        return new self(self::appendCleanupFailures($message, $cleanupFailures), 0, $failure);
    }

    /**
     * @param list<array{non-empty-string, \Throwable}> $cleanupFailures
     */
    private static function appendCleanupFailures(string $message, array $cleanupFailures): string
    {
        foreach ($cleanupFailures as [$fixture, $failure]) {
            $message .= \sprintf(
                "\nAdditionally, cleanup for integration fixture \"%s\" failed: %s",
                $fixture,
                self::sentence($failure->getMessage()),
            );
        }

        return $message;
    }

    private static function sentence(string $message): string
    {
        $message = \rtrim($message);

        if ($message === '') {
            return 'No message was provided.';
        }

        if (\preg_match('/[.!?]\z/', $message) === 1) {
            return $message;
        }

        return $message . '.';
    }
}

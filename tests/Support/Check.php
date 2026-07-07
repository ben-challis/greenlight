<?php

declare(strict_types=1);

namespace Greenlight\Tests\Support;

use Greenlight\Core\Test\ExpectationCounter;

/**
 * Minimal assertion helper for the bootstrap era, replaced by Greenlight\Expect
 * once that exists. Do not grow this beyond what the bootstrap-phase tests need.
 */
final class Check
{
    private function __construct() {}

    public static function same(mixed $expected, mixed $actual, string $what = 'value'): void
    {
        ExpectationCounter::increment();
        if ($expected !== $actual) {
            throw new \RuntimeException(\sprintf(
                'Expected %s to be %s, got %s.',
                $what,
                \var_export($expected, true),
                \var_export($actual, true),
            ));
        }
    }

    public static function true(bool $condition, string $what): void
    {
        ExpectationCounter::increment();
        if (!$condition) {
            throw new \RuntimeException(\sprintf('Expected %s.', $what));
        }
    }

    /**
     * @param class-string<\Throwable> $throwableClass
     */
    public static function throws(callable $callable, string $throwableClass, string $what = 'callable'): void
    {
        ExpectationCounter::increment();
        try {
            $callable();
        } catch (\Throwable $e) {
            if (!$e instanceof $throwableClass) {
                throw new \RuntimeException(\sprintf(
                    'Expected %s to throw %s, got %s: %s',
                    $what,
                    $throwableClass,
                    $e::class,
                    $e->getMessage(),
                ), $e->getCode(), $e);
            }

            return;
        }

        throw new \RuntimeException(\sprintf('Expected %s to throw %s, nothing was thrown.', $what, $throwableClass));
    }

    /**
     * Encodes to JSON and back, simulating the wire.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function jsonRoundTrip(array $payload): array
    {
        $decoded = \json_decode(\json_encode($payload, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);

        if (!\is_array($decoded)) {
            throw new \RuntimeException('Wire payload did not decode to an array.');
        }

        $map = [];

        foreach ($decoded as $key => $value) {
            $map[(string) $key] = $value;
        }

        return $map;
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Harness;

use Greenlight\Core\Wire\InvalidWirePayload;
use Greenlight\Core\Wire\Wire;
use Greenlight\Core\Wire\WireSerializable;

/**
 * JSON-safe information exposed by one orchestrator-owned integration fixture.
 *
 * Ordinary values and secrets are kept separate so debug output can redact
 * credentials. Secrets are strings and require an explicit `reveal()` call.
 */
final readonly class FixtureResource implements WireSerializable
{
    private const int MAX_DEPTH = 16;

    /**
     * @param array<string, mixed> $values
     * @param array<string, string> $secrets
     */
    private function __construct(private array $values, #[\SensitiveParameter] array $secrets)
    {
        // A closure prevents var_export() from rendering credential strings.
        $this->revealSecrets = static fn(): array => $secrets;
    }

    /**
     * @var \Closure(): array<string, string>
     */
    private \Closure $revealSecrets;

    /**
     * @param array<string, mixed> $values
     * @param array<mixed> $secrets
     */
    public static function from(array $values = [], #[\SensitiveParameter] array $secrets = []): self
    {
        self::validateMap($values, 'values');
        $validatedSecrets = [];

        foreach ($secrets as $key => $secret) {
            if (!\is_string($key)
                || $key === ''
                || !self::validUtf8($key)
                || !\is_string($secret)
                || !self::validUtf8($secret)
            ) {
                throw new \InvalidArgumentException('Fixture secrets must be a map of non-empty UTF-8 string keys to UTF-8 strings.');
            }

            $validatedSecrets[$key] = $secret;
        }

        return new self($values, $validatedSecrets);
    }

    public static function empty(): self
    {
        return new self([], []);
    }

    public function has(string $key): bool
    {
        return \array_key_exists($key, $this->values) || \array_key_exists($key, $this->secrets());
    }

    public function value(string $key): mixed
    {
        if (!\array_key_exists($key, $this->values)) {
            throw new \OutOfBoundsException(\sprintf('Fixture resource has no ordinary value named "%s".', $key));
        }

        return $this->values[$key];
    }

    public function string(string $key): string
    {
        $value = $this->value($key);

        if (!\is_string($value)) {
            throw $this->wrongType($key, 'a string', $value);
        }

        return $value;
    }

    public function int(string $key): int
    {
        $value = $this->value($key);

        if (!\is_int($value)) {
            throw $this->wrongType($key, 'an integer', $value);
        }

        return $value;
    }

    public function float(string $key): float
    {
        $value = $this->value($key);

        if (!\is_float($value) && !\is_int($value)) {
            throw $this->wrongType($key, 'a float', $value);
        }

        return (float) $value;
    }

    public function bool(string $key): bool
    {
        $value = $this->value($key);

        if (!\is_bool($value)) {
            throw $this->wrongType($key, 'a boolean', $value);
        }

        return $value;
    }

    /**
     * @return list<mixed>
     */
    public function list(string $key): array
    {
        $value = $this->value($key);

        if (!\is_array($value) || !\array_is_list($value)) {
            throw $this->wrongType($key, 'a list', $value);
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    public function map(string $key): array
    {
        $value = $this->value($key);

        if (!\is_array($value) || \array_is_list($value)) {
            throw $this->wrongType($key, 'a map', $value);
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    public function secret(string $key): SensitiveValue
    {
        $secrets = $this->secrets();

        if (!\array_key_exists($key, $secrets)) {
            throw new \OutOfBoundsException(\sprintf('Fixture resource has no secret named "%s".', $key));
        }

        return new SensitiveValue($secrets[$key]);
    }

    public function mergedWith(self $channel): self
    {
        return new self(
            [...$this->values, ...$channel->values],
            [...$this->secrets(), ...$channel->secrets()],
        );
    }

    #[\Override]
    public function toWire(): array
    {
        return [
            'values' => $this->values,
            'secrets' => $this->secrets(),
        ];
    }

    #[\Override]
    public static function fromWire(array $payload): static
    {
        $values = Wire::map($payload, 'values');
        $rawSecrets = Wire::map($payload, 'secrets');
        $secrets = [];

        foreach ($rawSecrets as $key => $secret) {
            if ($key === '' || !\is_string($secret)) {
                throw InvalidWirePayload::wrongType('secrets', 'a map of non-empty string keys to strings', $rawSecrets);
            }

            $secrets[$key] = $secret;
        }

        try {
            return self::from($values, $secrets);
        } catch (\InvalidArgumentException) {
            throw InvalidWirePayload::wrongType('resource', 'JSON-safe values and string secrets', $payload);
        }
    }

    /**
     * @return array{values: array<string, mixed>, secrets: array<string, string>}
     */
    public function __debugInfo(): array
    {
        return [
            'values' => $this->values,
            'secrets' => \array_fill_keys(\array_keys($this->secrets()), '[redacted]'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function secrets(): array
    {
        return ($this->revealSecrets)();
    }

    private function wrongType(string $key, string $expected, mixed $actual): \UnexpectedValueException
    {
        return new \UnexpectedValueException(\sprintf(
            'Fixture resource value "%s" must be %s, got %s.',
            $key,
            $expected,
            \get_debug_type($actual),
        ));
    }

    /**
     * @param array<mixed> $map
     */
    private static function validateMap(array $map, string $path, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException(\sprintf('Fixture resource "%s" exceeds the maximum nesting depth.', $path));
        }

        foreach ($map as $key => $value) {
            if (!\is_string($key) || $key === '' || !self::validUtf8($key)) {
                throw new \InvalidArgumentException('Fixture resource maps need non-empty UTF-8 string keys.');
            }

            self::validateValue($value, $path . '.' . $key, $depth + 1);
        }
    }

    private static function validateValue(mixed $value, string $path, int $depth): void
    {
        if ($value === null || \is_int($value) || \is_bool($value)) {
            return;
        }

        if (\is_string($value)) {
            if (!self::validUtf8($value)) {
                throw new \InvalidArgumentException(\sprintf('Fixture resource "%s" must contain valid UTF-8 strings.', $path));
            }

            return;
        }

        if (\is_float($value)) {
            if (!\is_finite($value)) {
                throw new \InvalidArgumentException(\sprintf('Fixture resource "%s" must contain finite numbers.', $path));
            }

            return;
        }

        if (!\is_array($value)) {
            throw new \InvalidArgumentException(\sprintf(
                'Fixture resource "%s" must be JSON-safe, got %s.',
                $path,
                \get_debug_type($value),
            ));
        }

        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException(\sprintf('Fixture resource "%s" exceeds the maximum nesting depth.', $path));
        }

        if (\array_is_list($value)) {
            foreach ($value as $index => $item) {
                self::validateValue($item, $path . '.' . $index, $depth + 1);
            }

            return;
        }

        self::validateMap($value, $path, $depth);
    }

    private static function validUtf8(string $value): bool
    {
        return \preg_match('//u', $value) === 1;
    }
}

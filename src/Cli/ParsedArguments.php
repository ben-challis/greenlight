<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * The outcome of parsing argv: at most one command word plus the options that
 * were present. A null entry in an option's value list means the option was
 * given without a value (allowed for flags and optional-value options).
 *
 * @internal
 */
final readonly class ParsedArguments
{
    /**
     * @param array<string, list<string|null>> $options
     */
    public function __construct(
        public ?string $command,
        public array $options,
    ) {}

    public function has(string $name): bool
    {
        return isset($this->options[$name]);
    }

    /**
     * The last value given for the option, which is null when the option was
     * present without a value or absent entirely. Use has() to tell those
     * apart.
     */
    public function value(string $name): ?string
    {
        $values = $this->options[$name] ?? [];

        return $values === [] ? null : $values[\array_key_last($values)];
    }

    /**
     * All non-null values given for a repeatable option, in order.
     *
     * @return list<string>
     */
    public function values(string $name): array
    {
        $values = [];

        foreach ($this->options[$name] ?? [] as $value) {
            if ($value !== null) {
                $values[] = $value;
            }
        }

        return $values;
    }
}

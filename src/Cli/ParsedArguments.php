<?php

declare(strict_types=1);

namespace Greenlight\Cli;

/**
 * Contains at most one command word and the options from argv.
 *
 * A null entry in an option value list means that the option had no value.
 * Flags and options with optional values permit this form.
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
     * Returns the last value for the option.
     *
     * Returns null if the option was absent or had no value. Use has() to
     * distinguish these conditions.
     */
    public function value(string $name): ?string
    {
        $values = $this->options[$name] ?? [];

        return $values === [] ? null : $values[\array_key_last($values)];
    }

    /**
     * Returns all non-null values for a repeatable option in input order.
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

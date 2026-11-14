<?php

declare(strict_types=1);

namespace Greenlight\Cli\Input;

/**
 * Contains the command word and the known options from a lenient scan.
 *
 * @internal
 */
final readonly class ArgumentScan
{
    /**
     * @param array<string, list<string|null>> $options
     */
    public function __construct(
        public ?string $command,
        public ?int $commandIndex,
        public array $options,
    ) {}

    public function has(string $name): bool
    {
        return isset($this->options[$name]);
    }

    /** Returns the last value of the option. */
    public function value(string $name): ?string
    {
        $values = $this->options[$name] ?? [];

        return $values === [] ? null : $values[\array_key_last($values)];
    }
}

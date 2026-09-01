<?php

declare(strict_types=1);

namespace Greenlight\Cli\Plugin;

use Greenlight\Command\CommandDefinition;

/** @internal */
final readonly class CommandCatalog
{
    /** @var array<non-empty-string, CommandDefinition> */
    private array $definitions;

    /**
     * @param list<CommandDefinition> $definitions
     *
     * @throws CommandSetupFailed
     */
    public function __construct(array $definitions)
    {
        $indexed = [];

        foreach ($definitions as $definition) {
            if (isset($indexed[$definition->name])) {
                throw CommandSetupFailed::duplicateName($definition->name);
            }

            $indexed[$definition->name] = $definition;
        }

        $this->definitions = $indexed;
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function get(string $name): ?CommandDefinition
    {
        return $this->definitions[$name] ?? null;
    }
}

<?php

declare(strict_types=1);

namespace Greenlight\Command;

/** Defines one named command-line command. */
final readonly class CommandDefinition
{
    /** @var non-empty-string */
    public string $name;

    /** @var non-empty-string */
    public string $description;

    /**
     * @param \Closure(CommandInvocation): CommandResult $handler
     *
     * @throws \InvalidArgumentException
     */
    public function __construct(
        string $name,
        string $description,
        private \Closure $handler,
    ) {
        if (\preg_match('/^[a-z][a-z0-9]*(?:[:-][a-z0-9]+)*$/D', $name) !== 1) {
            throw new \InvalidArgumentException(
                'Command names MUST start with a lowercase ASCII letter. They MUST contain only lowercase ASCII letters, digits, hyphens, or colons.',
            );
        }

        if ($description === '' || \str_contains($description, "\n") || \str_contains($description, "\r")) {
            throw new \InvalidArgumentException('Command descriptions MUST be non-empty single-line strings.');
        }

        $this->name = $name;
        $this->description = $description;
    }

    /** @internal Greenlight runs command definitions. */
    public function run(CommandInvocation $invocation): CommandResult
    {
        return ($this->handler)($invocation);
    }
}
